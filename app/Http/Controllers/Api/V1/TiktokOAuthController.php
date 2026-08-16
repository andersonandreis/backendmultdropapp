<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\MarketplaceAccount;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * SEL-046/047: OAuth TikTok Login Kit -- RELAY CENTRAL (api.hubai.io).
 *
 * Regra matriz-unica: chaves TikTok vivem SO na api.hubai.io. Todas as WLs
 * (seller.global, fornecefy, multdrop, mestoredrop) chamam esse init passando
 * ?service=<wl_id>&user_id=<id>&return_url=<url> e o hub redireciona pro
 * authorize e depois pra WL com querystring de resultado. Padrao identico ao
 * Shopee (api.hubai.io/api/shopee/oauth/init).
 *
 * SEL-047: O callback agora tambem cria/atualiza um MarketplaceAccount espelho
 * com platform='tiktok' -- padrao que permite que SyncTikTokOrdersJob e
 * SyncInventoryJob (via MarketplaceFactory) operem sobre a conta sem adaptar
 * todos os jobs para ler de tiktok_shop_connections diretamente.
 *
 * Endpoints:
 *   GET /api/tiktok/oauth/init?service=<wl>&user_id=<id>&return_url=<url>
 *       Redireciona (302) pro authorize do TikTok.
 *   GET /oauth/tiktok/callback?code=&state=
 *       Troca code por access_token, grava em tiktok_shop_connections,
 *       cria/atualiza MarketplaceAccount espelho e redireciona pra return_url
 *       com ?tiktok_status=ok|error.
 */
class TiktokOAuthController extends Controller
{
    private function cfg(): array
    {
        return [
            'key'      => config('services.tiktok.app_key'),
            'secret'   => config('services.tiktok.app_secret'),
            'auth'     => rtrim(config('services.tiktok.auth_url') ?: 'https://www.tiktok.com/v2/auth/authorize', '/'),
            'api'      => rtrim(config('services.tiktok.api_url') ?: 'https://open.tiktokapis.com', '/'),
            'redirect' => config('services.tiktok.redirect_uri') ?: 'https://api.hubai.io/oauth/tiktok/callback',
        ];
    }

    /**
     * GET /api/tiktok/oauth/init?service=&user_id=&return_url=
     * Padrao identico ao Shopee -- chamado pelo frontend da WL.
     */
    public function init(Request $r)
    {
        $cfg = $this->cfg();
        if (! $cfg['key']) {
            return response()->json(['error' => 'tiktok_not_configured'], 503);
        }
        $service   = (string) $r->query('service', 'seller-global');
        $userId    = (int) $r->query('user_id', 0);
        $returnUrl = (string) $r->query('return_url', 'https://seller.global/integracoes');
        if (! $userId) {
            return response()->json(['error' => 'missing_user_id'], 422);
        }
        $state = Str::uuid()->toString();
        Cache::put("tt_oauth:{$state}", [
            'service'    => $service,
            'user_id'    => $userId,
            'return_url' => $returnUrl,
        ], 900);

        $qs = http_build_query([
            'client_key'    => $cfg['key'],
            'scope'         => 'user.info.basic,video.upload',
            'response_type' => 'code',
            'redirect_uri'  => $cfg['redirect'],
            'state'         => $state,
        ]);
        return redirect()->to($cfg['auth'] . '?' . $qs);
    }

    /**
     * GET /oauth/tiktok/callback?code=&state=
     *
     * SEL-047: alem de gravar em tiktok_shop_connections, cria/atualiza
     * o MarketplaceAccount espelho (platform='tiktok') vinculado ao client
     * correspondente ao user_id OAuth. Tokens ficam em access_token/refresh_token
     * no registro padrao -- exatamente como ML e Shopee. O campo
     * tiktok_connection_id aponta para o registro em tiktok_shop_connections
     * para auditoria.
     */
    public function callback(Request $r)
    {
        $cfg       = $this->cfg();
        $code      = $r->query('code');
        $state     = $r->query('state');
        $ctx       = $state ? Cache::pull("tt_oauth:{$state}") : null;
        $returnUrl = ($ctx['return_url'] ?? 'https://seller.global/integracoes');
        $userId    = (int) ($ctx['user_id'] ?? 0);
        $service   = (string) ($ctx['service'] ?? 'seller-global');
        if (! $code || ! $state || ! $ctx) {
            return redirect()->to($returnUrl . '?tiktok_status=error&reason=invalid_state');
        }

        try {
            $res = Http::asForm()->withHeaders([
                'Content-Type'  => 'application/x-www-form-urlencoded',
                'Cache-Control' => 'no-cache',
            ])->timeout(30)->post($cfg['api'] . '/v2/oauth/token/', [
                'client_key'    => $cfg['key'],
                'client_secret' => $cfg['secret'],
                'code'          => $code,
                'grant_type'    => 'authorization_code',
                'redirect_uri'  => $cfg['redirect'],
            ]);
            if (! $res->successful()) {
                Log::warning('[SEL-046] token exchange fail', ['status' => $res->status(), 'body' => $res->body()]);
                return redirect()->to($returnUrl . '?tiktok_status=error&reason=token_exchange');
            }
            $body             = $res->json();
            $access           = $body['access_token'] ?? null;
            $refresh          = $body['refresh_token'] ?? null;
            $expiresIn        = (int) ($body['expires_in'] ?? 0);
            $refreshExpiresIn = (int) ($body['refresh_expires_in'] ?? 0);
            $openId           = $body['open_id'] ?? '';
            $scope            = (string) ($body['scope'] ?? '');

            if (! $access) {
                Log::warning('[SEL-046] no access_token in body', ['body' => $body]);
                return redirect()->to($returnUrl . '?tiktok_status=error&reason=no_access');
            }

            $now = time();

            // 1. Gravar/atualizar tiktok_shop_connections (fonte de verdade OAuth)
            DB::table('tiktok_shop_connections')->updateOrInsert(
                ['user_id' => $userId, 'shop_id' => $openId ?: 'no_shop'],
                [
                    'shop_name'                => null,
                    'shop_region'              => 'BR',
                    'seller_id'                => $service,
                    'open_id'                  => $openId ?: null,
                    'access_token'             => $access,
                    'refresh_token'            => $refresh,
                    'access_token_expire_at'   => $now + max(0, $expiresIn),
                    'refresh_token_expire_at'  => $now + max(0, $refreshExpiresIn),
                    'grant_type'               => 'authorization_code',
                    'scopes'                   => $scope,
                    'status'                   => 'active',
                    'updated_at'               => now(),
                    'created_at'               => now(),
                ]
            );

            // Recuperar o ID do registro recém gravado para usar como ponteiro
            $connRow = DB::table('tiktok_shop_connections')
                ->where('user_id', $userId)
                ->where('shop_id', $openId ?: 'no_shop')
                ->first();
            $connectionId = $connRow?->id;

            // 2. SEL-047: criar/atualizar MarketplaceAccount espelho (platform='tiktok')
            // Permite que SyncTikTokOrdersJob e SyncInventoryJob (MarketplaceFactory)
            // iterem sobre marketplace_accounts exatamente como fazem com ML e Shopee.
            // client_id e resolvido via user_id (regra 00-INDEX: user_id != client_id).
            $client = DB::table('clients')->where('user_id', $userId)->first();
            if ($client) {
                $accountData = [
                    'platform'              => 'tiktok',
                    'shop_id'               => $openId ?: null,
                    'access_token'          => $access,
                    'refresh_token'         => $refresh,
                    'token_expires_at'      => $expiresIn > 0
                                                ? now()->addSeconds($expiresIn)->toDateTimeString()
                                                : null,
                    'refresh_token_expires_at' => $refreshExpiresIn > 0
                                                ? now()->addSeconds($refreshExpiresIn)->toDateTimeString()
                                                : null,
                    'tiktok_connection_id'  => $connectionId,
                    'service'               => $service,
                    'status'                => 'active',
                    'account_name'          => 'TikTok Shop (' . ($openId ?: 'no_shop') . ')',
                    'needs_reauth'          => false,
                    'updated_at'            => now(),
                ];

                // Verificar se ja existe conta tiktok para este client+shop
                $existing = MarketplaceAccount::where('client_id', $client->id)
                    ->where('platform', 'tiktok')
                    ->where('shop_id', $openId ?: null)
                    ->first();

                if ($existing) {
                    $existing->update($accountData);
                    Log::info('[SEL-047] MarketplaceAccount tiktok atualizado', [
                        'account_id'    => $existing->id,
                        'client_id'     => $client->id,
                        'shop_id'       => $openId,
                        'connection_id' => $connectionId,
                    ]);
                } else {
                    $accountData['client_id'] = $client->id;
                    $accountData['created_at'] = now();
                    MarketplaceAccount::create($accountData);
                    Log::info('[SEL-047] MarketplaceAccount tiktok criado', [
                        'client_id'     => $client->id,
                        'shop_id'       => $openId,
                        'connection_id' => $connectionId,
                    ]);
                }
            } else {
                Log::warning('[SEL-047] Nao foi possivel criar MarketplaceAccount: client nao encontrado para user_id', [
                    'user_id' => $userId,
                ]);
            }

            return redirect()->to($returnUrl . '?tiktok_status=ok&shop=' . urlencode($openId ?: 'account'));
        } catch (\Throwable $e) {
            Log::warning('[SEL-046] callback exception', ['err' => $e->getMessage()]);
            return redirect()->to($returnUrl . '?tiktok_status=error&reason=exception');
        }
    }

    /** GET /api/v1/tiktok/oauth/status (auth Sanctum) */
    public function status(Request $r)
    {
        $row = DB::table('tiktok_shop_connections')
            ->where('user_id', $r->user()->id)
            ->where('status', 'active')
            ->orderByDesc('id')
            ->first();
        return response()->json([
            'connected'              => (bool) $row,
            'open_id'                => $row->open_id ?? null,
            'shop_id'                => $row->shop_id ?? null,
            'access_token_expire_at' => $row->access_token_expire_at ?? null,
        ]);
    }

    /** POST /api/v1/tiktok/oauth/disconnect */
    public function disconnect(Request $r)
    {
        DB::table('tiktok_shop_connections')
            ->where('user_id', $r->user()->id)
            ->update(['status' => 'revoked', 'updated_at' => now()]);

        // SEL-047: revogar tambem o MarketplaceAccount espelho
        $client = DB::table('clients')->where('user_id', $r->user()->id)->first();
        if ($client) {
            MarketplaceAccount::where('client_id', $client->id)
                ->where('platform', 'tiktok')
                ->update(['status' => 'revoked', 'updated_at' => now()]);
        }

        return response()->json(['ok' => true]);
    }
}
