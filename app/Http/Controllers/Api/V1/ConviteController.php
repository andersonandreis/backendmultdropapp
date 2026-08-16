<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\DeviceFingerprintService;
use App\Services\InviteTrialService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Http;
use Illuminate\Validation\ValidationException;

/**
 * SEL-CONVITE Fase A — POST /api/convite/start
 *
 * Ponto UNICO de entrada do link /convite. Recebe o id_token do Google (mesma
 * validacao do AuthController::googleLogin, self-contained pra nao colidir com
 * ele) + o fingerprint do dispositivo, e decide o destino:
 *
 *   mode=normal          -> ja é pagante/admin/afiliado -> login normal (entra).
 *   mode=trial           -> abre/retoma trial de 24h + pode gerar 1 video.
 *   mode=trial_consumed  -> email ja queimou o trial -> parede de upgrade (403, sem token).
 *   mode=waitlist        -> mode=waitlist OU teto diario/limite device -> captura + standby.
 *
 * Toda a blindagem é aqui (server = fonte da verdade). O front so exibe.
 */
class ConviteController extends Controller
{
    public function start(Request $request)
    {
        $data = $request->validate([
            'id_token'                     => 'required|string',
            'fingerprint'                  => 'nullable|array',
            'fingerprint.canvas'           => 'nullable|string|max:512',
            'fingerprint.webgl'            => 'nullable|string|max:512',
            'fingerprint.screen'           => 'nullable|string|max:128',
            'fingerprint.platform'         => 'nullable|string|max:64',
            'fingerprint.timezone'         => 'nullable|string|max:64',
            'fingerprint.language'         => 'nullable|string|max:16',
            'fingerprint.hardwareConcurrency' => 'nullable|integer|min:0|max:255',
            'fingerprint.deviceMemory'     => 'nullable|integer|min:0|max:255',
            'fingerprint.isHeadless'       => 'nullable|boolean',
        ]);

        // 1) Valida o id_token no Google (self-contained).
        $email = $this->verifyGoogle($data['id_token']);

        $fpData = $data['fingerprint'] ?? [];
        $ip     = $request->ip();

        // Grava o fingerprint (event='convite') e recupera o hash + flags anti-bot.
        $fpRes = DeviceFingerprintService::record($request, $fpData, 'convite', null);
        $fpHash      = $fpRes['fingerprint_hash'] ?? null;
        $isHeadless  = (bool) ($fpData['isHeadless'] ?? false);

        $user = User::where('email', $email)->first();

        // 2) Ja pagante/admin/afiliado -> LOGIN NORMAL (nunca vira trial).
        if ($user && (InviteTrialService::isPaying($user) || InviteTrialService::isAffiliate($user->id))) {
            if ($motivo = $user->motivoSemAcesso()) {
                return response()->json(['message' => $motivo], 403);
            }
            return $this->issueToken($user, 'normal');
        }

        // 3) Email ja QUEIMOU o trial -> parede de upgrade (sem token).
        if (InviteTrialService::consumedEmail($email)) {
            return response()->json([
                'mode'    => 'trial_consumed',
                'offer'   => InviteTrialService::offer(),
                'message' => 'Seu teste gratuito já foi usado. Desbloqueie tudo com o plano completo.',
            ], 403);
        }

        // 3b) Se ja existe trial ATIVO pra esse usuario -> retoma (F5/reabrir aba).
        if ($user) {
            $activeTrial = InviteTrialService::activeTrial($user->id);
            if ($activeTrial) {
                return $this->issueToken($user, 'trial', $activeTrial);
            }
        }

        // 4) Anti-abuso -> captura na waitlist (melhor que rejeitar: vira lead).
        $blocked = $isHeadless
            || DeviceFingerprintService::isDatacenterIp($ip)
            || InviteTrialService::fingerprintTrialsUsed($fpHash) >= InviteTrialService::MAX_PER_FP
            || InviteTrialService::ipTrials24h($ip) >= InviteTrialService::MAX_PER_IP_24H;

        // 5) Toggle de carga: waitlist explicito OU teto diario batido.
        $mode = InviteTrialService::mode();
        $capHit = InviteTrialService::trialVideosToday() >= InviteTrialService::dailyCap();

        if ($mode === 'waitlist' || $capHit || $blocked) {
            InviteTrialService::addToWaitlist($email, $fpHash, $ip);
            return response()->json([
                'mode'    => 'waitlist',
                'message' => 'Você entrou na lista de espera! Assim que abrir uma vaga do teste, te avisamos por e-mail.',
            ], 200);
        }

        // 6) mode=open + dentro do teto + device limpo -> abre o trial.
        if (! $user) {
            $user = User::create([
                'name'              => explode('@', $email)[0],
                'email'             => $email,
                'password'          => bcrypt(bin2hex(random_bytes(16))),
                'role'              => 'client',
                'is_active'         => 1,
                'email_verified_at' => now(),
            ]);
        } elseif ($motivo = $user->motivoSemAcesso()) {
            return response()->json(['message' => $motivo], 403);
        }

        $trial = InviteTrialService::startTrial($user->id, $email, $fpHash, $ip);
        // amarra o user_id no registro de fingerprint desse convite (auditoria)
        if ($fpHash) {
            \Illuminate\Support\Facades\DB::table('device_fingerprints')
                ->where('fingerprint_hash', $fpHash)->whereNull('user_id')
                ->where('event', 'convite')->orderByDesc('id')->limit(1)
                ->update(['user_id' => $user->id]);
        }

        return $this->issueToken($user, 'trial', $trial);
    }

    /* ---------------- helpers ---------------- */

    private function verifyGoogle(string $idToken): string
    {
        try {
            $resp = Http::timeout(6)->get('https://oauth2.googleapis.com/tokeninfo', ['id_token' => $idToken]);
            if (! $resp->ok()) {
                throw ValidationException::withMessages(['id_token' => ['Token do Google inválido.']]);
            }
            $payload = $resp->json();
        } catch (\Illuminate\Http\Client\ConnectionException $e) {
            abort(503, 'Não foi possível validar o Google agora.');
        }

        $expectedAud = config('services.google.client_id');
        if (! $expectedAud) {
            try {
                $dbClient = \DB::table('settings')->where('key', 'google_client_id')->value('value');
                if ($dbClient) { $expectedAud = decrypt($dbClient); }
            } catch (\Throwable $e) { /* ignora */ }
        }
        if ($expectedAud && ($payload['aud'] ?? null) !== $expectedAud) {
            throw ValidationException::withMessages(['id_token' => ['Google client_id não bate.']]);
        }

        $email = strtolower($payload['email'] ?? '');
        if (! $email || empty($payload['email_verified'])) {
            throw ValidationException::withMessages(['id_token' => ['E-mail Google não verificado.']]);
        }
        return $email;
    }

    private function issueToken(User $user, string $mode, $trial = null)
    {
        // 2FA: se a conta tiver 2FA confirmado, cai no login por senha (raro no convite).
        if ($user->two_factor_confirmed_at) {
            return response()->json([
                'mode'    => 'twofa',
                'message' => 'Sua conta tem verificação em duas etapas. Faça login por e-mail e senha.',
            ], 409);
        }

        Auth::login($user);
        try { $user->revokeOtherPanelSessions(); } catch (\Throwable $e) { /* ignore */ }
        $token = $user->createToken('api-token')->plainTextToken;

        $out = [
            'mode'       => $mode,
            'token'      => $token,
            'token_type' => 'Bearer',
            'user'       => ['id' => $user->id, 'name' => $user->name, 'email' => $user->email, 'role' => $user->role],
        ];
        if ($mode === 'trial' && $trial) {
            $out['trial'] = [
                'kind'        => 'convite',
                'active'      => true,
                'expired'     => false,
                'expires_at'  => \Carbon\Carbon::parse($trial->expires_at)->toIso8601String(),
                'video_used'  => ! is_null($trial->video_used_at),
                'video_limit' => 1,
            ];
            $out['offer'] = InviteTrialService::offer();
        }
        return response()->json($out, 200);
    }
}
