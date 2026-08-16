<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Client;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * SEL-272 Ruan 19/07 15:09 — Firebase Phone Auth (SMS).
 *
 * Fluxo:
 *  1. Cliente digita telefone no frontend → Firebase SDK → SMS enviado
 *  2. Cliente digita código → Firebase confirma → retorna idToken
 *  3. Frontend chama POST /api/v1/auth/phone/verify {id_token}
 *  4. Backend valida idToken via Firebase REST (getAccountInfo), extrai phone_e164
 *  5. Vincula com User existente (por phone_e164 ou firebase_uid) ou cria novo
 *  6. Devolve token Sanctum + user completo (mesmo shape do /api/login)
 *
 * Sem Firebase Admin SDK PHP (evita dependência pesada) — validação via
 * https://identitytoolkit.googleapis.com/v1/accounts:lookup usando Web API Key.
 */
class PhoneAuthController extends Controller
{
    public function verify(Request $r)
    {
        $r->validate([
            'id_token' => 'required|string|min:20',
        ]);

        $apiKey = config('services.firebase.web_api_key') ?: env('FIREBASE_WEB_API_KEY');
        if (empty($apiKey)) {
            return response()->json(['error' => 'firebase_not_configured'], 503);
        }

        try {
            $resp = Http::timeout(6)->post(
                "https://identitytoolkit.googleapis.com/v1/accounts:lookup?key={$apiKey}",
                ['idToken' => $r->input('id_token')]
            );
        } catch (\Throwable $e) {
            Log::error('sel272 firebase lookup error', ['e' => $e->getMessage()]);
            return response()->json(['error' => 'firebase_lookup_failed'], 502);
        }

        if (!$resp->successful()) {
            return response()->json(['error' => 'invalid_token', 'upstream' => $resp->json()], 401);
        }

        $data = $resp->json('users.0');
        if (!$data) return response()->json(['error' => 'invalid_token'], 401);

        $firebaseUid = $data['localId'] ?? null;
        $phoneE164 = $data['phoneNumber'] ?? null;
        if (!$firebaseUid || !$phoneE164) {
            return response()->json(['error' => 'no_phone_in_token'], 422);
        }

        // 1. Buscar user por firebase_uid ou phone
        $user = User::where('firebase_uid', $firebaseUid)
            ->orWhere('phone_e164', $phoneE164)
            ->first();

        if (!$user) {
            // 2. Criar novo user + client sem senha (só telefone)
            $user = DB::transaction(function () use ($phoneE164, $firebaseUid) {
                $email = 'phone_' . preg_replace('/\D/', '', $phoneE164) . '@seller.global';
                $u = User::create([
                    'name'              => 'Novo usuário',
                    'email'             => $email,
                    'password'          => bcrypt(Str::random(32)),
                    'role'              => 'client',
                    'phone_e164'        => $phoneE164,
                    'phone_verified_at' => now(),
                    'firebase_uid'      => $firebaseUid,
                    'is_active'         => true,
                ]);
                Client::create([
                    'user_id'    => $u->id,
                    'name'       => 'Novo usuário',
                    'email'      => $email,
                    'phone'      => $phoneE164,
                    'is_active'  => true,
                ]);
                return $u;
            });
        } else {
            // 3. Existente — atualiza dados de vínculo
            $user->update([
                'firebase_uid'      => $firebaseUid,
                'phone_e164'        => $phoneE164,
                'phone_verified_at' => now(),
            ]);
        }

        // SEL-XXX (10/08): anti-revenda -- mesma sessao unica do login por
        // senha/Google (ver User::revokeOtherPanelSessions). Login por telefone
        // no aparelho B derruba os outros canais do aparelho A.
        $user->revokeOtherPanelSessions();

        // 4. Emite token Sanctum
        $token = $user->createToken('phone-auth')->plainTextToken;

        // Load subscription/plan pra shape igual /api/login
        $user->load(['subscription.plan']);

        return response()->json([
            'token' => $token,
            'user'  => $user,
        ]);
    }
}
