<?php

namespace App\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class MigrateShopeeUserToNovoHubAIJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;
    public int $timeout = 60;

    public function __construct(
        public readonly int    $legadoUserId,
        public readonly int    $shopId,
        public readonly string $accessToken,
        public readonly string $refreshToken,
        public readonly int    $expireIn,
    ) {}

    public function handle(): void
    {
        try {
            // 1. Verifica se client ja existe no NovoHubAI
            $client = \App\Models\Client::where('legacy_id_login', $this->legadoUserId)->first();

            // 2. Se nao existe, busca dados no legado via bridge e cria
            if (!$client) {
                $userInfo = $this->fetchLegadoUserInfo($this->legadoUserId);
                if (!$userInfo) {
                    Log::warning('[MigrateShopeeUserJob] Nao foi possivel buscar dados do legado', [
                        'legacy_user_id' => $this->legadoUserId,
                    ]);
                    return;
                }

                $email = $userInfo['email'] ?? "legado_{$this->legadoUserId}@hubai.io";
                $user = \App\Models\User::firstOrCreate(
                    ['email' => $email],
                    [
                        'name'     => $userInfo['name'] ?? "Cliente {$this->legadoUserId}",
                        'password' => bcrypt(\Illuminate\Support\Str::random(32)),
                    ]
                );

                // MUL-269 fase 2: company_name removido de clients — nome vem do user (accessor).
                $client = \App\Models\Client::updateOrCreate(
                    ['user_id' => $user->id],
                    [
                        'legacy_id_login' => $this->legadoUserId,
                        'phone'           => $userInfo['phone'] ?? null,
                        'document'        => $userInfo['document'] ?? null,
                        'is_active'       => true,
                    ]
                );

                Log::info('[MigrateShopeeUserJob] Client criado no NovoHubAI', [
                    'legacy_user_id' => $this->legadoUserId,
                    'client_id'      => $client->id,
                    'user_id'        => $user->id,
                ]);
            }

            // 3. Cria ou atualiza marketplace_account com tokens encriptados
            // NOV-180: migracao legado->hubai nao pertence ao multdrop; supplier fica NULL
            $supplierId = null;
            \App\Models\MarketplaceAccount::updateOrCreate(
                [
                    'client_id' => $client->id,
                    'platform'  => 'shopee',
                    'shop_id'   => (string) $this->shopId,
                ],
                [
                    'access_token'             => encrypt($this->accessToken),
                    'refresh_token'            => encrypt($this->refreshToken),
                    'token_expires_at'         => now()->addSeconds($this->expireIn),
                    'refresh_token_expires_at' => now()->addDays(30),
                    'service'                  => 'hubai',
                    'status'                   => 'active',
                    'last_token_refresh_at'    => now(),
                    'seller_id'                => (string) $this->shopId,
                    'supplier_id'              => $supplierId, // NOV-102: fix supplier_id NULL
                ]
            );

            Log::info('[MigrateShopeeUserJob] marketplace_account sincronizado', [
                'legacy_user_id' => $this->legadoUserId,
                'client_id'      => $client->id,
                'shop_id'        => $this->shopId,
            ]);

        } catch (\Throwable $e) {
            Log::error('[MigrateShopeeUserJob] Erro na migracao lazy', [
                'legacy_user_id' => $this->legadoUserId,
                'error'          => $e->getMessage(),
            ]);
            throw $e; // permite retry pela fila
        }
    }

    /**
     * Busca dados do usuario no legado via endpoint bridge HTTP (HMAC-assinado).
     * Retorna array com email, name, company_name, phone, document ou null em falha.
     */
    private function fetchLegadoUserInfo(int $legadoUserId): ?array
    {
        try {
            $bridgeKey = config('services.goolhub.bridge_key', 'hb-bridge-2026-xK9mP3qR7vL2nW8');
            $sig = hash_hmac('sha256', "getuser:{$legadoUserId}", $bridgeKey);

            $response = Http::timeout(5)
                ->get('https://goolhub.io/api/bridge/shopee_get_user_info.php', [
                    'legacy_id' => $legadoUserId,
                    'sig'       => $sig,
                ]);

            if ($response->successful() && $response->json('success')) {
                return $response->json();
            }

            Log::warning('[MigrateShopeeUserJob] Bridge retornou erro ao buscar user info', [
                'legacy_user_id' => $legadoUserId,
                'status'         => $response->status(),
                'body'           => substr($response->body(), 0, 300),
            ]);
            return null;

        } catch (\Throwable $e) {
            Log::error('[MigrateShopeeUserJob] Falha ao chamar bridge shopee_get_user_info', [
                'legacy_user_id' => $legadoUserId,
                'error'          => $e->getMessage(),
            ]);
            return null;
        }
    }
}
