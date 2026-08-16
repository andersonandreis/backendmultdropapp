<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * MES-010 — Importa integracoes marketplace do legado para api.mestoredrop.com.br
 *
 * Para cada integracao em legacy.integracao (id_empresa=20, removida=0):
 *  1. Resolve user via legacy.login.email -> users.email (banco novo)
 *  2. Garante client (firstOrCreate) com legacy_id_login = integracao.id_login
 *  3. Cria marketplace_account (canais 1,3,5,6,12,20) ou ignora canais nao suportados (7,8,13)
 *  4. Usa coluna legacy_id para idempotencia (updateOrCreate)
 *
 * Mapeamento de canais:
 *   1  -> magalu
 *   3  -> shopee
 *   5  -> shopee (com flag modo_ferias no settings)
 *   6  -> mercadolivre
 *   12 -> mercadolivre (status disconnected — meli_dessincronizado=S)
 *   20 -> bling (gravado em marketplace_accounts pois a tabela suporta bling_*)
 *   7,8,13 -> ignorados (sem suporte/relevancia)
 *
 * Uso:
 *   php artisan import:legacy-marketplace-accounts --dry-run
 *   php artisan import:legacy-marketplace-accounts --limit=50
 *   php artisan import:legacy-marketplace-accounts
 */
class ImportLegacyMarketplaceAccounts extends Command
{
    protected $signature = 'import:legacy-marketplace-accounts
                            {--dry-run : Preview sem gravar nada}
                            {--limit=0 : Limitar N integracoes (0=todas)}
                            {--supplier-id=25 : supplier_id no novo}
                            {--id-empresa=20 : id_empresa no legado}';

    protected $description = 'MES-010: Importa integracoes marketplace (Shopee/ML/Bling/Magalu) do legado para mestoredrop';

    private const CHANNEL_MAP = [
        1  => ['platform' => 'magalu',       'status' => 'active'],
        3  => ['platform' => 'shopee',       'status' => 'active'],
        5  => ['platform' => 'shopee',       'status' => 'active',       'modo_ferias' => true],
        6  => ['platform' => 'mercadolivre', 'status' => 'active'],
        12 => ['platform' => 'mercadolivre', 'status' => 'disconnected'],
        20 => ['platform' => 'bling',        'status' => 'active'],
    ];

    private const CHANNEL_LABEL = [
        1 => 'Magalu', 3 => 'Shopee', 5 => 'Shopee (Modo Ferias)',
        6 => 'ML', 7 => 'B2W', 8 => 'Magalu Bloq', 12 => 'ML Dessinc',
        13 => 'Manual', 20 => 'Bling V3',
    ];

    private bool $dry = false;
    private int $supplierId = 25;
    private int $idEmpresa = 20;

    // contadores
    private int $totalProcessed = 0;
    private int $createdAccounts = 0;
    private int $updatedAccounts = 0;
    private int $skippedNoUser = 0;
    private int $skippedNoChannel = 0;
    private int $skippedNoClient = 0;
    private int $createdClients = 0;
    private int $errors = 0;
    /** @var array<string,int> */
    private array $byPlatform = [];

    public function handle(): int
    {
        $this->dry = (bool) $this->option('dry-run');
        $this->supplierId = (int) $this->option('supplier-id');
        $this->idEmpresa = (int) $this->option('id-empresa');
        $limit = (int) $this->option('limit');

        $this->info(sprintf(
            'MES-010 ImportLegacyMarketplaceAccounts %s | supplier_id=%d id_empresa=%d limit=%s',
            $this->dry ? '[DRY-RUN]' : '[LIVE]',
            $this->supplierId,
            $this->idEmpresa,
            $limit > 0 ? (string) $limit : 'ALL'
        ));

        // 1. Pre-carrega user_id por email
        $userByEmail = DB::table('users')
            ->select('id', 'email')
            ->get()
            ->keyBy(fn ($u) => strtolower(trim($u->email)));
        $this->info("Users pre-carregados: {$userByEmail->count()}");

        // 2. Pre-carrega clients por legacy_id_login
        $clientByLegacy = DB::table('clients')->whereNotNull('legacy_id_login')->pluck('id', 'legacy_id_login');
        $this->info("Clients pre-carregados: {$clientByLegacy->count()}");

        // 3. Pre-carrega emails do legado para login_ids relevantes
        $loginsLegacy = DB::connection('legacy')
            ->table('login')
            ->select('id', 'email', 'login as username', 'nome_completo', 'empresa', 'cpf_cnpj')
            ->whereIn('id', function ($q) {
                $q->select('id_login')->from('integracao')
                    ->where('id_empresa', $this->idEmpresa)
                    ->where('removida', 0);
            })
            ->get()
            ->keyBy('id');
        $this->info("Legacy logins relacionados: {$loginsLegacy->count()}");

        // 4. Itera integracoes
        $supportedChannels = array_keys(self::CHANNEL_MAP);
        $query = DB::connection('legacy')
            ->table('integracao')
            ->where('id_empresa', $this->idEmpresa)
            ->where('removida', 0);

        if ($limit > 0) {
            $query->limit($limit);
        }

        $integracoes = $query->get();
        $totalCount = $integracoes->count();
        $this->info("Integracoes a processar: {$totalCount}");

        $bar = $this->output->createProgressBar($totalCount);
        $bar->start();

        foreach ($integracoes as $integ) {
            $this->totalProcessed++;
            $bar->advance();

            $canal = (int) $integ->id_canal;

            if (! in_array($canal, $supportedChannels, true)) {
                $this->skippedNoChannel++;
                continue;
            }

            // Resolve client
            $clientId = $clientByLegacy[$integ->id_login] ?? null;

            if (! $clientId) {
                // Tentar criar via legacy.login email
                $login = $loginsLegacy[$integ->id_login] ?? null;
                if (! $login || empty($login->email)) {
                    $this->skippedNoUser++;
                    continue;
                }

                $emailKey = strtolower(trim($login->email));
                $user = $userByEmail[$emailKey] ?? null;
                if (! $user) {
                    $this->skippedNoUser++;
                    continue;
                }

                if (! $this->dry) {
                    try {
                        // MUL-269 fase 2: company_name removido de clients — nome vem do user.
                        $clientId = DB::table('clients')->insertGetId([
                            'user_id'         => $user->id,
                            'legacy_id_login' => $integ->id_login,
                            'document'        => $login->cpf_cnpj ?: null,
                            'created_at'      => now(),
                            'updated_at'      => now(),
                        ]);
                        $clientByLegacy[$integ->id_login] = $clientId;
                        $this->createdClients++;
                    } catch (\Throwable $e) {
                        $this->errors++;
                        $this->warn("[CLIENT-ERR] login={$integ->id_login} email={$emailKey} :: " . $e->getMessage());
                        continue;
                    }
                } else {
                    $clientId = -1;
                    $this->createdClients++;
                }
            }

            $map = self::CHANNEL_MAP[$canal];
            $platform = $map['platform'];

            try {
                $this->upsertMarketplaceAccount($integ, $platform, $map, (int) $clientId);
            } catch (\Throwable $e) {
                $this->errors++;
                $msg = $e->getMessage();
                $this->warn("[MKT-ERR] integ={$integ->id} canal={$canal} :: " . substr($msg, 0, 160));
            }
        }

        $bar->finish();
        $this->newLine(2);

        $this->table(
            ['Metric', 'Valor'],
            [
                ['Total integracoes processadas',  $this->totalProcessed],
                ['Marketplace accounts criadas',    $this->createdAccounts],
                ['Marketplace accounts atualizadas',$this->updatedAccounts],
                ['Clients criados',                 $this->createdClients],
                ['Skipped — canal nao suportado',  $this->skippedNoChannel],
                ['Skipped — sem user no novo',     $this->skippedNoUser],
                ['Skipped — sem client',           $this->skippedNoClient],
                ['Errors',                          $this->errors],
            ]
        );

        if (! empty($this->byPlatform)) {
            $rows = [];
            foreach ($this->byPlatform as $p => $c) {
                $rows[] = [$p, $c];
            }
            $this->table(['Platform', 'Criadas'], $rows);
        }

        if ($this->dry) {
            $this->warn('DRY-RUN: nenhuma gravacao realizada.');
        }

        return self::SUCCESS;
    }

    private function upsertMarketplaceAccount(object $integ, string $platform, array $map, int $clientId): void
    {
        $canal = (int) $integ->id_canal;

        $shopId = $integ->usuario ?: null;
        $shopName = $integ->shop_name ?: ($integ->nome_loja_shopee ?: $integ->descricao);

        $accountName = $shopName ?: $integ->descricao;
        $accountName = trim((string) $accountName) ?: ("Legacy #" . $integ->id);

        $payload = [
            'client_id'    => $clientId,
            'supplier_id'  => $this->supplierId,
            'platform'     => $platform,
            'account_name' => mb_substr($accountName, 0, 250),
            'status'       => $map['status'],
            'import_mode'  => 'manual',
            'shop_id'      => $shopId ? mb_substr((string) $shopId, 0, 255) : null,
            'updated_at'   => now(),
        ];

        if ($platform === 'shopee') {
            if (! empty($integ->v2_shopee_access_token)) {
                $payload['access_token']     = $integ->v2_shopee_access_token;
                $payload['refresh_token']    = $integ->v2_shopee_refresh_token;
                $payload['token_expires_at'] = $integ->v2_shopee_data_token;
            }
            if (! empty($integ->id_app_shopee)) {
                $payload['app_id'] = (string) $integ->id_app_shopee;
            }
            if (! empty($integ->nome_loja_shopee)) {
                $payload['seller_nickname'] = $integ->nome_loja_shopee;
            }
        } elseif ($platform === 'mercadolivre') {
            if (! empty($integ->meli_access_token)) {
                $payload['ml_access_token']     = $integ->meli_access_token;
                $payload['ml_refresh_token']    = $integ->meli_refresh_token;
                $payload['ml_token_expires_at'] = $integ->meli_data_token;
                $payload['access_token']        = $integ->meli_access_token;
                $payload['refresh_token']       = $integ->meli_refresh_token;
                $payload['token_expires_at']    = $integ->meli_data_token;
            }
            if ($shopId) {
                $payload['ml_user_id'] = (string) $shopId;
                $payload['seller_id']  = (string) $shopId;
            }
        } elseif ($platform === 'bling') {
            if (! empty($integ->bling_access_token)) {
                $payload['bling_access_token']     = $integ->bling_access_token;
                $payload['bling_refresh_token']    = $integ->bling_refresh_token;
                $payload['bling_token_expires_at'] = $integ->bling_data_token;
            }
            if ($integ->bling_id_loja) {
                $payload['shop_id'] = (string) $integ->bling_id_loja;
            }
        } elseif ($platform === 'magalu') {
            if (! empty($integ->magalu_access_token)) {
                $payload['access_token']     = $integ->magalu_access_token;
                $payload['refresh_token']    = $integ->magalu_refresh_token;
                $payload['token_expires_at'] = $integ->magalu_data_token;
            }
        }

        if ($this->dry) {
            $this->byPlatform[$platform] = ($this->byPlatform[$platform] ?? 0) + 1;
            $this->createdAccounts++;
            return;
        }

        $existing = DB::table('marketplace_accounts')->where('legacy_id', $integ->id)->first();

        if ($existing) {
            DB::table('marketplace_accounts')->where('id', $existing->id)->update($payload);
            $this->updatedAccounts++;
            $this->byPlatform[$platform] = ($this->byPlatform[$platform] ?? 0) + 1;
            return;
        }

        if ($shopId) {
            $dupe = DB::table('marketplace_accounts')
                ->where('platform', $platform)
                ->where('shop_id', $payload['shop_id'])
                ->first();
            if ($dupe) {
                $payload['shop_id'] = $payload['shop_id'] . '#L' . $integ->id;
            }
        }

        $payload['legacy_id']  = $integ->id;
        $payload['created_at'] = now();
        DB::table('marketplace_accounts')->insert($payload);
        $this->createdAccounts++;
        $this->byPlatform[$platform] = ($this->byPlatform[$platform] ?? 0) + 1;
    }
}
