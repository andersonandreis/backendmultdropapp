<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\MarketplaceAccount;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * FOR-081 -- Endpoint para verificar status das integracoes do cliente autenticado.
 *
 * GET /api/v1/me/integrations/status
 *
 * Retorna lista de contas com token expirado, status critico ou needs_reauth=1.
 * Criterio canonico definido em FOR-077.
 * Usado pelo frontend para exibir banner de alerta no painel.
 */
class IntegrationStatusController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $client = $request->user()?->client;

        if (! $client) {
            return response()->json(['expired_accounts' => [], 'has_expired' => false]);
        }

        $now = now();

        $expiredAccounts = MarketplaceAccount::where('client_id', $client->id)
            ->where(function ($q) use ($now) {
                $q->where(function ($sq) use ($now) {
                    $sq->whereNotNull('token_expires_at')
                       ->where('token_expires_at', '<', $now);
                })->orWhere(function ($sq) use ($now) {
                    $sq->whereNotNull('refresh_token_expires_at')
                       ->where('refresh_token_expires_at', '<', $now);
                })->orWhere(function ($sq) use ($now) {
                    $sq->whereNotNull('ml_token_expires_at')
                       ->where('ml_token_expires_at', '<', $now);
                })->orWhereIn('status', \App\Services\Integrations\PendenciaContaService::statusQueTravam())
                  ->orWhere('needs_reauth', 1);
            })
            ->get(['id', 'platform', 'account_name', 'status', 'needs_reauth',
                   'token_expires_at', 'refresh_token_expires_at', 'ml_token_expires_at'])
            ->map(function ($account) {
                // SEL-422: o texto que o cliente le vem do backend, nao da tela.
                // Antes daqui so saia o codigo do status ('kyc_pendente') e cada
                // tela inventava a frase — e o link era SEMPRE o de reconectar,
                // que nao resolve KYC nenhum.
                $p = \App\Services\Integrations\PendenciaContaService::descrever(
                    $account->status, $account->platform, $account->id
                );

                return [
                    'id'             => $account->id,
                    'platform'       => $account->platform,
                    'account_name'   => $account->account_name,
                    'status'         => $account->status,
                    'motivo'         => $p['motivo'],
                    'titulo'         => $p['titulo'],
                    'mensagem'       => $p['mensagem'],
                    'acao_url'       => $p['acao_url'],
                    'acao_label'     => $p['acao_label'],
                    'acao_externa'   => $p['externo'],
                    // reauth_url mantido pra nao quebrar quem ja consome. Use acao_url.
                    'reauth_url'     => $p['externo'] ? null : $p['acao_url'],
                    // can_auto_renew: refresh ainda valido, sistema pode tentar renovar automaticamente
                    'can_auto_renew' => $account->refresh_token_expires_at
                        ? $account->refresh_token_expires_at->gt(now())
                        : false,
                ];
            });

        return response()->json([
            'has_expired'       => $expiredAccounts->isNotEmpty(),
            'expired_accounts'  => $expiredAccounts->values(),
            'count_by_platform' => $expiredAccounts->groupBy('platform')->map->count(),
        ]);
    }
}
