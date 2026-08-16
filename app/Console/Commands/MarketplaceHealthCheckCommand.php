<?php

namespace App\Console\Commands;

use App\Models\MarketplaceAccount;
use App\Services\Integrations\Marketplaces\ShopeeService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

/**
 * marketplace:health-check
 *
 * Verifica saude das conexoes Shopee ativas.
 * NOV-082 (2026-06-25): tenta refresh antes de expired + usa needs_reauth
 * em vez de expired para o recover-tokens pegar no proximo ciclo.
 */
class MarketplaceHealthCheckCommand extends Command
{
    protected $signature   = 'marketplace:health-check';
    protected $description = 'Verifica saude das conexoes marketplace ativas';

    public function handle(ShopeeService $shopee): int
    {
        $accounts = MarketplaceAccount::where('platform', 'shopee')
            ->where('status', 'active')
            ->whereNotNull('access_token')
            ->whereNotNull('shop_id')
            ->get();

        $this->info("Verificando {$accounts->count()} conta(s) Shopee ativa(s)...");

        $ok        = 0;
        $recovered = 0;
        $expired   = 0;
        $errors    = 0;

        foreach ($accounts as $account) {
            try {
                $result = $this->pingShopee($shopee, $account);

                if (isset($result['error']) && $result['error'] !== '') {
                    $errCode = $result['error'] ?? 'unknown';

                    $isAuthError = in_array($errCode, [
                        'error_auth', 'error_param_token', 'error_permission',
                        'error_auth_partner', 'invalid_access_token',
                    ]);

                    if ($isAuthError) {
                        // NOV-082: tenta refresh antes de desistir
                        $refreshed = false;
                        try {
                            $newToken = $shopee->refreshToken($account);
                            if ($newToken) {
                                $account->update([
                                    'status'               => 'active',
                                    'sync_errors_count'    => 0,
                                    'refresh_errors_count' => 0,
                                    'sync_blocked_at'      => null,
                                    'last_error_message'   => null,
                                    'last_token_refresh_at' => now(),
                                ]);
                                $refreshed = true;
                                $recovered++;
                                $this->info("Conta {$account->shop_id} (id={$account->id}) RECUPERADA via refresh apos erro {$errCode}");
                                Log::channel('marketplace')->info('[HealthCheck] Conta Shopee recuperada via refresh apos erro auth', [
                                    'account_id' => $account->id,
                                    'shop_id'    => $account->shop_id,
                                    'error_code' => $errCode,
                                ]);
                            }
                        } catch (\Throwable $e) {
                            Log::channel('marketplace')->warning('[HealthCheck] Refresh falhou para conta', [
                                'account_id' => $account->id,
                                'shop_id'    => $account->shop_id,
                                'error'      => $e->getMessage(),
                            ]);
                        }

                        if (!$refreshed) {
                            // Refresh falhou -- marcar como needs_reauth (nao expired)
                            $account->update(['status' => 'needs_reauth']);
                            $expired++;
                            $this->warn("Conta {$account->shop_id} (id={$account->id}) -> needs_reauth (refresh falhou): {$errCode}");
                            Log::channel('marketplace')->warning('[HealthCheck] Conta Shopee marcada needs_reauth (refresh falhou)', [
                                'account_id' => $account->id,
                                'shop_id'    => $account->shop_id,
                                'client_id'  => $account->client_id,
                                'error'      => $errCode,
                            ]);
                        }
                    } else {
                        $errors++;
                        $this->error("Conta {$account->shop_id} (id={$account->id}) ERRO: {$errCode}");
                        Log::channel('marketplace')->error('[HealthCheck] Erro nao-auth na conta Shopee', [
                            'account_id' => $account->id,
                            'shop_id'    => $account->shop_id,
                            'error'      => $result,
                        ]);
                    }
                } else {
                    $shopName = $result['response']['shop_name'] ?? "shop_{$account->shop_id}";
                    $ok++;
                    $this->info("Conta {$account->shop_id} OK: {$shopName}");
                    Log::channel('marketplace')->info('[HealthCheck] Conta Shopee OK', [
                        'account_id' => $account->id,
                        'shop_id'    => $account->shop_id,
                        'shop_name'  => $shopName,
                    ]);
                }
            } catch (\Throwable $e) {
                $errors++;
                $this->error("Conta {$account->shop_id} (id={$account->id}) EXCECAO: {$e->getMessage()}");
                Log::channel('marketplace')->error('[HealthCheck] Excecao ao verificar conta', [
                    'account_id' => $account->id,
                    'error'      => $e->getMessage(),
                ]);
            }
        }

        $this->info("Resultado: {$ok} OK | {$recovered} recuperadas via refresh | {$expired} -> needs_reauth | {$errors} erros");

        Log::channel('marketplace')->info('[HealthCheck] Ciclo concluido', [
            'ok'        => $ok,
            'recovered' => $recovered,
            'expired'   => $expired,
            'errors'    => $errors,
            'total'     => $accounts->count(),
        ]);

        $this->varrerBloqueadasPorKyc($shopee);

        return self::SUCCESS;
    }

    /**
     * SEL-423 — devolve ao normal a conta que resolveu o KYC na Shopee.
     *
     * O impasse que isto resolve: a conta era marcada como pendente, o guard
     * passava a bloquear as chamadas, e nada nunca mais testava a conta. O
     * cliente concluia o cadastro na Shopee, fazia a parte dele, e o painel
     * continuava dizendo "seu cadastro esta incompleto" pra sempre. Quem faz o
     * certo e continua sendo acusado, cancela.
     *
     * Por que aqui e nao num cron novo: este comando JA sabia devolver conta ao
     * normal — so que o filtro dele e where('status','active'), entao a conta
     * bloqueada nunca era olhada. Era funcionalidade pronta apontada pro lugar
     * errado, igual ao alarme de credito e ao endpoint de aviso.
     *
     * A sonda e /api/v2/shop/get_shop_info, a mesma do ping. Ela NAO passa pelo
     * guard, que vive so dentro do syncProduct — entao nao precisa de bypass.
     * Medido em 30/07 na conta 1524: com KYC pendente o Shopee responde HTTP 403
     * error_kyc_auth; resolvido, responde sem erro.
     *
     * ATENCAO: no_shipping_channel NAO entra aqui. Medido na conta 1505: essa
     * sonda passa numa loja sem canal de envio, porque nao testa envio. Liberar
     * por este criterio tiraria o aviso e os pedidos continuariam sem entrar —
     * pior que o problema atual. Aquele caso pede get_channel_list, em separado.
     */
    private function varrerBloqueadasPorKyc(ShopeeService $shopee): void
    {
        // De hora em hora, ainda que o comando rode de 30 em 30 min: o cliente
        // nao resolve KYC em minutos, e martelar a API com conta bloqueada e
        // exatamente o que a gente acabou de parar de fazer.
        if (\Illuminate\Support\Facades\Cache::has('healthcheck:kyc:ultima-varredura')) {
            return;
        }
        \Illuminate\Support\Facades\Cache::put('healthcheck:kyc:ultima-varredura', now()->toIso8601String(), 3300);

        $bloqueadas = MarketplaceAccount::where('platform', 'shopee')
            ->whereIn('status', \App\Services\Integrations\PendenciaContaService::KYC)
            ->whereNotNull('access_token')
            ->whereNotNull('shop_id')
            ->get();

        if ($bloqueadas->isEmpty()) {
            return;
        }

        $this->info("Verificando {$bloqueadas->count()} conta(s) com KYC pendente...");
        $liberadas = 0;

        foreach ($bloqueadas as $account) {
            try {
                $r   = $this->pingShopee($shopee, $account);
                $err = $r['error'] ?? '';

                if ($err === 'error_kyc_auth') {
                    // Estado esperado, nao e falha: o cliente ainda nao concluiu.
                    // De proposito sem log de erro, pra nao virar ruido de hora em hora.
                    continue;
                }

                if ($err !== '') {
                    Log::channel('marketplace')->warning('[HealthCheck KYC] erro diferente de KYC — conta segue bloqueada', [
                        'account_id' => $account->id, 'shop_id' => $account->shop_id, 'error' => $err,
                    ]);
                    continue;
                }

                $bloqueadaDesde = $account->sync_blocked_at ?? $account->updated_at;

                $account->update([
                    'status'             => 'active',
                    'sync_blocked_at'    => null,
                    'last_error_message' => null,
                    'sync_errors_count'  => 0,
                ]);
                $liberadas++;

                $this->info("Conta {$account->shop_id} (id={$account->id}) LIBERADA — KYC concluido na Shopee");
                Log::channel('marketplace')->info('[HealthCheck KYC] conta liberada — cadastro concluido na Shopee', [
                    'account_id'      => $account->id,
                    'shop_id'         => $account->shop_id,
                    'client_id'       => $account->client_id,
                    'bloqueada_desde' => $bloqueadaDesde ? (string) $bloqueadaDesde : null,
                    'horas_bloqueada' => $bloqueadaDesde ? (int) abs(now()->diffInHours($bloqueadaDesde)) : null,
                ]);
            } catch (\Throwable $e) {
                Log::channel('marketplace')->warning('[HealthCheck KYC] excecao ao verificar conta', [
                    'account_id' => $account->id, 'erro' => $e->getMessage(),
                ]);
            }
        }

        $this->info("KYC: {$liberadas} conta(s) liberada(s) de {$bloqueadas->count()} verificada(s)");
        Log::channel('marketplace')->info('[HealthCheck KYC] varredura concluida', [
            'verificadas' => $bloqueadas->count(),
            'liberadas'   => $liberadas,
        ]);
    }

    private function pingShopee(ShopeeService $shopee, MarketplaceAccount $account): array
    {
        $partnerId  = (int) config('services.shopee.partner_id');
        $partnerKey = config('services.shopee.partner_key');
        $shopId     = (int) $account->shop_id;

        try {
            $accessToken = decrypt($account->access_token);
        } catch (\Throwable $e) {
            $accessToken = $account->access_token;
        }

        $timestamp = time();
        $path      = '/api/v2/shop/get_shop_info';
        $sign      = hash_hmac('sha256', $partnerId . $path . $timestamp . $accessToken . $shopId, $partnerKey);

        $response = \Illuminate\Support\Facades\Http::timeout(10)->get(
            'https://partner.shopeemobile.com/api/v2/shop/get_shop_info',
            [
                'partner_id'   => $partnerId,
                'timestamp'    => $timestamp,
                'access_token' => $accessToken,
                'shop_id'      => $shopId,
                'sign'         => $sign,
            ]
        );

        return $response->json() ?? ['error' => 'empty_response'];
    }
}