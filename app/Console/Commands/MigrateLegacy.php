<?php

namespace App\Console\Commands;

use App\Models\Client;
use App\Models\MarketplaceAccount;
use App\Models\Order;
use App\Models\Payment;
use App\Models\Supplier;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * MigrateLegacy - importa dados do legado (tudoonline_production)
 * para o hubai-plataforma matriz (hubaiapp).
 *
 * Entidades importadas por execucao:
 *   1. Users + Clients        - loja WHERE id_empresa = ?
 *   2. MarketplaceAccounts    - integracao WHERE id_empresa = ?
 *   3. Orders                 - pedidos WHERE id_loja IN (...)
 *   4. Payments               - loja_fatura WHERE id_loja IN (...)
 *
 * Mapeamento status pedido (legado int -> canonical_status):
 *   0 -> created   (pedido em processamento)
 *   1 -> shipped
 *   2 -> cancelled
 *   3 -> delivered
 *   4 -> returned
 *
 * Mapeamento plataforma (id_canal -> platform):
 *   1,8   -> magalu | 3,5 -> shopee | 6,12 -> ml | 7 -> b2w
 *   2,14,15,16,18,19,20 -> bling | 9,11 -> shopify | outros -> other
 */
class MigrateLegacy extends Command
{
    protected $signature = 'migrate:legacy
                            {--tenant= : Slug do tenant destino (ex: mestoredrop)}
                            {--source= : id_empresa do legado (ex: 20)}
                            {--dry-run : Simula sem gravar no banco}
                            {--limit=  : Limita quantidade de lojas a processar}';

    protected $description = 'Importa dados do legado (goolhub/tudoonline) para hubai-plataforma matriz';

    private const CANAL_PLATFORM = [
        1  => 'magalu',  2  => 'bling',   3  => 'shopee',  4  => 'other',
        5  => 'shopee',  6  => 'ml',      7  => 'b2w',     8  => 'magalu',
        9  => 'shopify', 10 => 'other',   11 => 'shopify', 12 => 'ml',
        13 => 'other',   14 => 'bling',   15 => 'bling',   16 => 'bling',
        17 => 'other',   18 => 'bling',   19 => 'bling',   20 => 'bling',
    ];

    private const ORDER_STATUS_MAP = [
        0 => 'created',
        1 => 'shipped',
        2 => 'cancelled',
        3 => 'delivered',
        4 => 'returned',
    ];

    private array $counts = [
        'users'                => ['created' => 0, 'updated' => 0, 'skipped' => 0],
        'clients'              => ['created' => 0, 'updated' => 0, 'skipped' => 0],
        'marketplace_accounts' => ['created' => 0, 'updated' => 0, 'skipped' => 0],
        'orders'               => ['created' => 0, 'updated' => 0, 'skipped' => 0],
        'payments'             => ['created' => 0, 'updated' => 0, 'skipped' => 0],
    ];

    public function handle(): int
    {
        $tenantSlug = $this->option('tenant');
        $sourceId   = (int) $this->option('source');
        $dryRun     = (bool) $this->option('dry-run');
        $limit      = $this->option('limit') !== null ? (int) $this->option('limit') : null;

        if (! $tenantSlug || ! $sourceId) {
            $this->error('--tenant e --source sao obrigatorios');
            return self::FAILURE;
        }

        $tenant = Tenant::where('slug', $tenantSlug)->first();
        if (! $tenant) {
            $this->error("Tenant '{$tenantSlug}' nao encontrado em hubaiapp.tenants");
            return self::FAILURE;
        }

        $supplier = Supplier::where('legacy_empresa_id', $sourceId)->first();
        if (! $supplier) {
            $this->warn("Nenhum supplier com legacy_empresa_id={$sourceId} encontrado.");
        } else {
            $this->info("Supplier vinculado: id={$supplier->id} slug={$supplier->slug}");
        }

        try {
            DB::connection('legacy')->selectOne('SELECT 1 as ok');
            $this->info('Conexao com legado OK');
        } catch (\Throwable $e) {
            $this->error('Falha na conexao com legado: ' . $e->getMessage());
            return self::FAILURE;
        }

        $mode = $dryRun ? '[DRY-RUN]' : '[LIVE]';
        $this->info("=== MIGRATE LEGACY  tenant={$tenantSlug}  source={$sourceId}  {$mode} ===");

        $lojas = DB::connection('legacy')
            ->table('loja')
            ->where('id_empresa', $sourceId)
            ->when($limit !== null, fn ($q) => $q->limit($limit))
            ->get();

        if ($lojas->isEmpty()) {
            $this->warn("Nenhuma loja encontrada para id_empresa={$sourceId}");
            return self::SUCCESS;
        }

        $lojaIds = $lojas->pluck('id')->toArray();
        $this->info('Lojas: ' . count($lojaIds) . ' (IDs: ' . implode(', ', $lojaIds) . ')');

        $legacyWalletTotal = (float) DB::connection('legacy')
            ->table('conta_corrente_loja')
            ->whereIn('id_loja', $lojaIds)
            ->sum('valor');

        $this->line("\n--- 1. Users + Clients ---");
        foreach ($lojas as $loja) {
            $this->processLoja($loja, $dryRun);
        }

        $this->processMarketplaceAccounts($sourceId, $lojaIds, $supplier, $dryRun);
        $this->processOrders($lojaIds, $supplier, $dryRun);
        $this->processPayments($lojaIds, $dryRun);

        $this->newLine();
        $this->info('=== RESULTADO ===');
        $rows = [];
        foreach ($this->counts as $entity => $c) {
            $rows[] = [$entity, $c['created'], $c['updated'], $c['skipped']];
        }
        $this->table(['Entidade', 'Criados', 'Atualizados', 'Ignorados'], $rows);

        $this->newLine();
        $this->info('=== RECONCILIACAO SALDO ===');
        $this->info('Saldo total legado (conta_corrente_loja): R$ ' . number_format($legacyWalletTotal, 2, ',', '.'));
        $this->info('Obs: hubaiapp nao possui tabela wallets - saldo logado para implementacao futura');

        if (! $dryRun) {
            Log::info('migrate:legacy reconciliation', [
                'tenant'              => $tenantSlug,
                'source'              => $sourceId,
                'legacy_wallet_total' => $legacyWalletTotal,
                'loja_ids'            => $lojaIds,
                'counts'              => $this->counts,
            ]);
        }

        $this->newLine();
        $this->info($dryRun ? '[DRY-RUN] Nenhum dado gravado.' : 'Migracao concluida com sucesso.');
        return self::SUCCESS;
    }

    private function processLoja(object $loja, bool $dryRun): void
    {
        $email = trim(strtolower($loja->email ?? ''));

        if (! $email || ! filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $this->warn("  SKIP loja id={$loja->id}: email invalido");
            $this->counts['users']['skipped']++;
            $this->counts['clients']['skipped']++;
            return;
        }

        $isActive = (bool) (($loja->ativo ?? 1) && ! ($loja->bloqueado ?? 0));

        if ($dryRun) {
            $existsUser   = User::where('email', $email)->exists();
            $existsClient = Client::where('legacy_id_login', $loja->id)->exists();
            $this->line("  [DRY] loja id={$loja->id} email={$email}");
            $this->line("    User: " . ($existsUser ? 'update' : 'create'));
            $this->line("    Client: " . ($existsClient ? 'update' : 'create'));
            $this->counts['users'][$existsUser ? 'updated' : 'created']++;
            $this->counts['clients'][$existsClient ? 'updated' : 'created']++;
            return;
        }

        DB::transaction(function () use ($loja, $email, $isActive) {
            [$user, $created] = $this->upsertUser($loja, $email, $isActive);
            $this->counts['users'][$created ? 'created' : 'updated']++;

            $clientCreated = $this->upsertClient($loja, $user->id, $isActive);
            $this->counts['clients'][$clientCreated ? 'created' : 'updated']++;

            $act = $created ? 'CRIADO' : 'ATUALIZADO';
            $this->line("  loja id={$loja->id} user_id={$user->id} [{$act}]");
        });
    }

    private function upsertUser(object $loja, string $email, bool $isActive): array
    {
        $existing = User::where('email', $email)->first();
        if ($existing) {
            $existing->update(['name' => $loja->nome ?? $email, 'is_active' => $isActive]);
            return [$existing, false];
        }

        $senha = trim($loja->senha ?? '');
        $passwordHash = ($senha && strlen($senha) <= 30)
            ? Hash::make($senha)
            : Hash::make(Str::random(24));

        $user = User::create([
            'name'      => $loja->nome ?? $email,
            'email'     => $email,
            'password'  => $passwordHash,
            'role'      => 'client',
            'is_active' => $isActive,
        ]);

        return [$user, true];
    }

    private function upsertClient(object $loja, int $userId, bool $isActive): bool
    {
        // MUL-269 fase 2: company_name removido de clients — nome vem do user (accessor).
        $data = [
            'user_id'      => $userId,
            'phone'        => $loja->celular ?? null,
            'document'     => $loja->cpf_cnpj ?? null,
            'is_active'    => $isActive,
            'listing_mode' => 'manual',
        ];

        $existing = Client::where('legacy_id_login', $loja->id)->first();
        if ($existing) {
            $existing->update($data);
            return false;
        }

        // SHIELD-AUDIT: respeita clients.user_id UNIQUE (UserObserver pode ter criado Client).
        $createPayload = array_merge($data, [
            'legacy_id_login'      => $loja->id,
            'legacy_password_type' => 'plaintext_migrated',
        ]);
        if (! empty($data['user_id'])) {
            Client::updateOrCreate(['user_id' => $data['user_id']], $createPayload);
        } else {
            Client::create($createPayload);
        }

        return true;
    }

    private function processMarketplaceAccounts(int $sourceId, array $lojaIds, ?Supplier $supplier, bool $dryRun): void
    {
        $this->line("\n--- 2. MarketplaceAccounts (integracao id_empresa={$sourceId}) ---");

        $integracoes = DB::connection('legacy')
            ->table('integracao')
            ->where('id_empresa', $sourceId)
            ->get();

        $this->info('  Total integracoes: ' . $integracoes->count());

        foreach ($integracoes as $intg) {
            $platform = self::CANAL_PLATFORM[$intg->id_canal] ?? 'other';

            $client = Client::where('legacy_id_login', $intg->id_login)->first();
            if (! $client && in_array((int) $intg->id_login, $lojaIds, true)) {
                $client = Client::whereIn('legacy_id_login', $lojaIds)->first();
            }

            if (! $client) {
                $this->counts['marketplace_accounts']['skipped']++;
                continue;
            }

            if ($dryRun) {
                $exists = MarketplaceAccount::where('client_id', $client->id)
                    ->where('platform', $platform)
                    ->where('seller_id', (string) $intg->id)
                    ->exists();
                $this->line("  [DRY] intg id={$intg->id} platform={$platform} " . ($exists ? 'update' : 'create'));
                $this->counts['marketplace_accounts'][$exists ? 'updated' : 'created']++;
                continue;
            }

            try {
                DB::transaction(function () use ($intg, $platform, $client, $supplier) {
                    [$access, $refresh, $expires] = $this->extractTokens($intg, $platform);
                    $accountName = $intg->usuario ?: ($intg->shop_name ?? ('Conta #' . $intg->id));

                    $result = MarketplaceAccount::updateOrCreate(
                        ['client_id' => $client->id, 'platform' => $platform, 'seller_id' => (string) $intg->id],
                        [
                            'supplier_id'      => $supplier?->id,
                            'account_name'     => $accountName,
                            'access_token'     => $access,
                            'refresh_token'    => $refresh,
                            'token_expires_at' => $expires,
                            'status'           => ($intg->removida ?? 0) ? 'inactive' : 'active',
                            'service'          => 'hubai',
                            'import_mode'      => 'manual',
                        ]
                    );
                    $this->counts['marketplace_accounts'][$result->wasRecentlyCreated ? 'created' : 'updated']++;
                });
            } catch (\Throwable $e) {
                $this->warn('  ERRO intg id=' . $intg->id . ': ' . $e->getMessage());
                $this->counts['marketplace_accounts']['skipped']++;
            }
        }
    }

    private function extractTokens(object $intg, string $platform): array
    {
        if ($platform === 'ml') {
            return [
                $intg->meli_access_token ?: null,
                $intg->meli_refresh_token ?: null,
                $intg->meli_data_token ? date('Y-m-d H:i:s', strtotime($intg->meli_data_token)) : null,
            ];
        }
        if ($platform === 'shopee') {
            return [
                $intg->v2_shopee_access_token ?: null,
                $intg->v2_shopee_refresh_token ?: null,
                $intg->v2_shopee_data_token ? date('Y-m-d H:i:s', strtotime($intg->v2_shopee_data_token)) : null,
            ];
        }
        if ($platform === 'bling') {
            return [
                $intg->bling_access_token ?: null,
                $intg->bling_refresh_token ?: null,
                $intg->bling_data_token ? date('Y-m-d H:i:s', strtotime($intg->bling_data_token)) : null,
            ];
        }
        return [null, null, null];
    }

    private function processOrders(array $lojaIds, ?Supplier $supplier, bool $dryRun): void
    {
        $this->line("\n--- 3. Orders (pedidos) ---");

        $total = (int) DB::connection('legacy')
            ->table('pedidos')
            ->whereIn('id_loja', $lojaIds)
            ->count();

        $this->info("  Total pedidos no legado: {$total}");

        if ($dryRun) {
            $legacyIds     = DB::connection('legacy')->table('pedidos')->whereIn('id_loja', $lojaIds)->pluck('id')->toArray();
            $existingCount = Order::whereIn('legacy_id', $legacyIds)->count();
            $newCount      = $total - $existingCount;
            $this->line("  [DRY] Seriam criados: {$newCount} | Seriam atualizados: {$existingCount}");
            $this->counts['orders']['created'] += $newCount;
            $this->counts['orders']['updated']  += $existingCount;
            return;
        }

        DB::connection('legacy')
            ->table('pedidos')
            ->whereIn('id_loja', $lojaIds)
            ->orderBy('id')
            ->chunk(500, function ($pedidos) use ($supplier) {
                foreach ($pedidos as $pedido) {
                    $this->upsertOrder($pedido, $supplier);
                }
                $created = $this->counts['orders']['created'];
                $updated = $this->counts['orders']['updated'];
                $this->line("  Chunk - criados: {$created} atualizados: {$updated}");
            });
    }

    private function upsertOrder(object $pedido, ?Supplier $supplier): void
    {
        try {
            $client = Client::where('legacy_id_login', $pedido->id_loja)->first();
            if (! $client) {
                $this->counts['orders']['skipped']++;
                return;
            }

            $canonicalStatus = self::ORDER_STATUS_MAP[$pedido->status] ?? 'created';
            $platform        = self::CANAL_PLATFORM[$pedido->id_canal] ?? 'other';
            $supplierId      = $supplier?->id ?? 10;

            DB::transaction(function () use ($pedido, $client, $supplierId, $canonicalStatus, $platform) {
                $result = Order::updateOrCreate(
                    ['legacy_id' => $pedido->id],
                    [
                        'client_id'                => $client->id,
                        'supplier_id'              => $supplierId,
                        'tenant_slug'              => 'mestoredrop',
                        'order_number'             => $pedido->nr_canal ?? ('LEG-' . $pedido->id),
                        'source'                   => $platform,
                        'external_order_id'        => $pedido->nr_canal ?? null,
                        'customer_name'            => $pedido->cliente_nome ?? null,
                        'customer_document_number' => $pedido->cliente_cpf ?? null,
                        'tracking_number'          => $pedido->rastreio ?? null,
                        'status'                   => $canonicalStatus,
                        'canonical_status'         => $canonicalStatus,
                        'subtotal'                 => (float) ($pedido->valor_total ?? 0),
                        'total'                    => (float) ($pedido->valor_total ?? 0),
                        'currency'                 => 'BRL',
                        'created_at'               => $pedido->data_pedido_canal
                                                        ?? $pedido->data_add
                                                        ?? now()->toDateTimeString(),
                    ]
                );
                $this->counts['orders'][$result->wasRecentlyCreated ? 'created' : 'updated']++;
            });
        } catch (\Throwable $e) {
            $this->warn('  ERRO order legacy_id=' . $pedido->id . ': ' . $e->getMessage());
            $this->counts['orders']['skipped']++;
        }
    }

    private function processPayments(array $lojaIds, bool $dryRun): void
    {
        $this->line("\n--- 4. Payments (loja_fatura) ---");

        $faturas = DB::connection('legacy')
            ->table('loja_fatura')
            ->whereIn('id_loja', $lojaIds)
            ->get();

        $this->info('  Total faturas: ' . $faturas->count());

        foreach ($faturas as $fatura) {
            $client     = Client::where('legacy_id_login', $fatura->id_loja)->first();
            $externalId = 'legacy_fatura_' . $fatura->id;

            if (! $client) {
                $this->counts['payments']['skipped']++;
                continue;
            }

            if ($dryRun) {
                $exists = Payment::where('external_id', $externalId)->exists();
                $this->counts['payments'][$exists ? 'updated' : 'created']++;
                continue;
            }

            try {
                DB::transaction(function () use ($fatura, $client, $externalId) {
                    $order = Order::where('client_id', $client->id)->orderBy('id')->first();
                    if (! $order) {
                        $this->counts['payments']['skipped']++;
                        return;
                    }

                    $result = Payment::updateOrCreate(
                        ['external_id' => $externalId],
                        [
                            'order_id'   => $order->id,
                            'client_id'  => $client->id,
                            'gateway'    => 'legacy',
                            'method'     => 'pix',
                            'amount'     => (float) ($fatura->valor ?? 0),
                            'status'     => ($fatura->status == 1) ? 'paid' : 'pending',
                            'paid_at'    => $fatura->data_pag ?? null,
                            'created_at' => $fatura->data_add ?? now()->toDateTimeString(),
                        ]
                    );
                    $this->counts['payments'][$result->wasRecentlyCreated ? 'created' : 'updated']++;
                });
            } catch (\Throwable $e) {
                $this->warn('  ERRO fatura id=' . $fatura->id . ': ' . $e->getMessage());
                $this->counts['payments']['skipped']++;
            }
        }
    }
}