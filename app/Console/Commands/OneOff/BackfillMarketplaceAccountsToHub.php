<?php

namespace App\Console\Commands\OneOff;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Encryption\Encrypter;
use Illuminate\Support\Str;

class BackfillMarketplaceAccountsToHub extends Command
{
    protected $signature = 'fornecefy:backfill-marketplace-accounts {--dry-run : So mostrar, nao executar} {--limit=0 : Limitar N accounts (0=todos)} {--only-new : So inserir conta que ainda nao existe no hub; nunca sobrescrever token de conta existente}';
    protected $description = 'Backfill marketplace_accounts do fornecefyapp -> hubaiapp com service=fornecefy, supplier_id=hub_supplier_id, wl_client_id=local client_id';

    public function handle(): int
    {
        $dry = $this->option('dry-run');
        $limit = (int) $this->option('limit');

        // 1. Encrypter do hub
        $hubKey = config('federation.hub_app_key') ?: env('HUB_APP_KEY');
        if (!$hubKey) { $this->error('HUB_APP_KEY nao definido no .env'); return 1; }
        $hubKeyDecoded = base64_decode(substr($hubKey, 7));
        $hubCrypt = new Encrypter($hubKeyDecoded, 'AES-256-CBC');

        // 2. Conexao hub
        config(['database.connections.hub' => [
            'driver' => 'mysql', 'host' => '127.0.0.1', 'port' => 3306,
            'database' => config('federation.hub_db_database', 'hubaiapp'),
            'username' => config('federation.hub_db_username') ?: env('HUB_DB_USERNAME'),
            'password' => config('federation.hub_db_password') ?: env('HUB_DB_PASSWORD'),
            'charset' => 'utf8mb4', 'collation' => 'utf8mb4_unicode_ci', 'prefix' => '', 'strict' => true,
        ]]);

        // 3. Iterar
        // FOR-127: traz nome e email do seller junto. O hub nao tem (nem deve ter) o
        // cliente da WL, entao sem isso o painel mostra o Seller em branco.
        $query = DB::table('marketplace_accounts as ma')
            ->leftJoin('suppliers as s', 's.id', '=', 'ma.supplier_id')
            ->leftJoin('clients as cl', 'cl.id', '=', 'ma.client_id')
            ->leftJoin('users as u', 'u.id', '=', 'cl.user_id')
            ->select('ma.*', 's.hub_supplier_id',
                'u.name as wl_u_name', 'u.full_name as wl_u_full_name', 'u.email as wl_u_email',
                'cl.trade_name as wl_cl_trade', 'cl.legal_name as wl_cl_legal')
            ->whereNotNull('s.hub_supplier_id');
        if ($limit > 0) $query->limit($limit);
        $rows = $query->get();

        $inserted = 0; $updated = 0; $skipped = 0; $errors = 0;
        $hub = DB::connection('hub');

        foreach ($rows as $r) {
            try {
                // Match no hub: seller_id/shop_id/ml_user_id por platform
                $matchQ = $hub->table('marketplace_accounts')->where('platform', $r->platform);
                if ($r->platform === 'shopee' && $r->shop_id) $matchQ->where('shop_id', $r->shop_id);
                elseif (in_array($r->platform, ['mercadolivre','mercado_livre']) && $r->ml_user_id) $matchQ->where('ml_user_id', $r->ml_user_id);
                elseif ($r->seller_id) $matchQ->where('seller_id', $r->seller_id);
                else { $skipped++; $this->warn("skip $r->id: sem seller/shop/ml_user_id"); continue; }

                $exists = $matchQ->first();

                // Reencrypt tokens
                // JT-014: decrypt() DESSERIALIZA, decryptString() nao. Como encrypt()
                // serializa de novo, usar decryptString aqui gravava DUAS camadas
                // (s:82:"s:74:"APP_USR-...") e o marketplace devolvia 403. O formato
                // nativo do hub tem UMA camada — conferido em conta nativa.
                $reAccess = $r->access_token ? $hubCrypt->encrypt(Crypt::decrypt($r->access_token)) : null;
                $reRefresh = $r->refresh_token ? $hubCrypt->encrypt(Crypt::decrypt($r->refresh_token)) : null;
                $reMlAccess = $r->ml_access_token ? $hubCrypt->encrypt(Crypt::decrypt($r->ml_access_token)) : null;
                $reMlRefresh = $r->ml_refresh_token ? $hubCrypt->encrypt(Crypt::decrypt($r->ml_refresh_token)) : null;

                $payload = [
                    'service' => 'fornecefy',
                    'platform' => $r->platform,
                    // FOR-123: NAO carimbar supplier na conta. O valor vinha de
                    // suppliers.hub_supplier_id (fornecefy 1 -> hub 13), com a intencao de
                    // dizer "esta conta e do WL cujo fornecedor e o JTDrop". Mas o pedido
                    // herda $account->supplier_id (WebhookOrderService:221), entao TODO
                    // pedido da conta nascia como mercadoria do JTDrop — inclusive produto
                    // proprio do seller. Foi essa contaminacao que gerou a JT-013/JT-019/
                    // FOR-121 em 13/08/2026.
                    //
                    // Com NULL o sistema deriva do produto do item (supplierFromItemsData,
                    // MUL-315: "a conta manda; se ela nao souber, a mercadoria sabe"), que e
                    // o comportamento correto. Quem identifica a origem do pedido e o campo
                    // `service`, nao o supplier.
                    'supplier_id' => null,
                    'client_id' => null,
                    'wl_client_id' => $r->client_id,
                    'seller_id' => $r->seller_id,
                    'shop_id' => $r->shop_id,
                    'ml_user_id' => $r->ml_user_id,
                    'seller_nickname' => $r->seller_nickname,
                    'account_name' => $r->account_name,
                    // FOR-127: mesma ordem de fallback do script for127-seller-do-wl.py.
                    // NUNCA usar document: 88% e placeholder '00000000000000'.
                    'wl_client_name' => self::nomeDoSeller($r),
                    'wl_client_email' => $r->wl_u_email ?: null,
                    'app_id' => $r->app_id,
                    'access_token' => $reAccess,
                    'refresh_token' => $reRefresh,
                    'token_expires_at' => $r->token_expires_at,
                    'refresh_token_expires_at' => $r->refresh_token_expires_at,
                    'ml_access_token' => $reMlAccess,
                    'ml_refresh_token' => $reMlRefresh,
                    'ml_token_expires_at' => $r->ml_token_expires_at,
                    'status' => $r->status,
                    'needs_reauth' => $r->needs_reauth,
                    'import_mode' => $r->import_mode ?? 'manual',
                    'updated_at' => now(),
                ];

                if ($exists) {
                    // JT-014: com --only-new, conta que ja existe no hub nao e tocada.
                    // O UPDATE sobrescreve access_token/refresh_token/status com o valor
                    // do WL — e para conta ja marcada centrally_managed=1 o dono do token
                    // passou a ser o hub. Sobrescrever ali derruba credencial boa.
                    if ($this->option('only-new')) { $skipped++; continue; }
                    if ($dry) { $this->line("[DRY] UPDATE hub.marketplace_accounts.id={$exists->id} (was forn.{$r->id})"); }
                    else { $hub->table('marketplace_accounts')->where('id', $exists->id)->update($payload); }
                    $updated++;
                } else {
                    $payload['created_at'] = now();
                    if ($dry) { $this->line("[DRY] INSERT hub.marketplace_accounts (forn.{$r->id} seller_id={$r->seller_id} platform={$r->platform})"); }
                    else { $hub->table('marketplace_accounts')->insert($payload); }
                    $inserted++;
                }
            } catch (\Throwable $e) {
                $errors++;
                $this->error("forn.{$r->id}: {$e->getMessage()}");
            }
        }

        $this->info("DRY-RUN=" . ($dry?'YES':'NO') . " | inserted={$inserted} updated={$updated} skipped={$skipped} errors={$errors}");
        return 0;
    }

    /**
     * FOR-127: nome do seller da WL, na ordem de fallback declarada na nota.
     * Nunca usa document (placeholder em 88% dos cadastros).
     */
    private static function nomeDoSeller(object $r): ?string
    {
        foreach (['wl_u_name', 'wl_u_full_name', 'wl_cl_trade', 'wl_cl_legal'] as $campo) {
            $valor = trim((string) ($r->{$campo} ?? ''));
            if ($valor !== '' && mb_strlen($valor) >= 2 && ! ctype_digit($valor)) {
                return mb_substr($valor, 0, 191);
            }
        }

        return null;
    }
}
