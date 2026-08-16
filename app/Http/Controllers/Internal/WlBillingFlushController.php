<?php

namespace App\Http\Controllers\Internal;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

/**
 * SEL-430 — Flush instantaneo do cache Redis do billing gate WL.
 *
 * POST /internal/wl-billing/flush-cache
 * Auth: X-Internal-Key (InternalKeyMiddleware — mesma chave do bridge legado)
 *
 * Chamado pelo api.seller.global/AdminWlController::toggleBlock() e
 * markPaidAndUnblock() apos gravar is_blocked=false no Supabase.
 * Garante que o EnforceWlBillingGate (SEL-424) veja o novo valor
 * imediatamente (< 1s) sem esperar o TTL de 60s expirar.
 *
 * Aceita empresa_nome:
 *   1) Via route param {empresa_nome} (rota admin: POST /api/v1/admin/wl-billing/{nome}/flush-cache)
 *   2) Via body JSON (rota interna: POST /api/internal/wl-billing/flush-cache)
 *
 * Resposta 200: { flushed: true, cache_key: "wl_billing_gate:dropksr" }
 * Resposta 422: { error: "empresa_nome obrigatorio" }
 */
class WlBillingFlushController extends Controller
{
    /** Mesmo mapa do EnforceWlBillingGate — empresa_nome -> slug da cache key */
    private const VALID_EMPRESAS = [
        'MultDrop'    => 'multdrop',
        'Fornecefy'   => 'fornecefy',
        'MEStoreDrop' => 'mestoredrop',
        'JTDrop'      => 'jtdrop',
        'DropKsr'     => 'dropksr',
    ];

    /**
     * Aceita empresa_nome via route param OU body JSON.
     */
    public function flush(Request $request, ?string $empresa_nome = null): \Illuminate\Http\JsonResponse
    {
        $empresaNome = $empresa_nome ?? $request->input('empresa_nome');

        if (!$empresaNome) {
            return response()->json(['error' => 'empresa_nome obrigatorio'], 422);
        }

        if (isset(self::VALID_EMPRESAS[$empresaNome])) {
            $slug = self::VALID_EMPRESAS[$empresaNome];
        } else {
            $slug = strtolower(str_replace(' ', '_', $empresaNome));
        }

        $cacheKey = 'wl_billing_gate:' . $slug;
        Cache::forget($cacheKey);

        Log::info('[SEL-430] wl_billing_gate cache flushed', [
            'empresa_nome' => $empresaNome,
            'cache_key'    => $cacheKey,
            'caller_ip'    => $request->ip(),
        ]);

        return response()->json([
            'flushed'   => true,
            'cache_key' => $cacheKey,
        ]);
    }
}
