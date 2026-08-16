<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * SEL-357: Copia pedidos (e futuramente client_products, wallet_transactions)
 * da conta espelho readonly de uma instalacao origem para o sellerapp.
 *
 * Uso: php artisan mirror:sync-from-multdrop {source_client_id} {target_client_id} [--limit=500]
 *
 * Atualmente suporta apenas source=multdrop. Para adicionar outras origens,
 * criar conexao no config/database.php e ajustar --source-backend.
 *
 * READONLY: este comando NUNCA escreve no backend de origem. Apenas lê.
 * Os dados copiados ficam com mirror_source_client_id referenciando a origem.
 */
class MirrorSyncCommand extends Command
{
    protected $signature = 'mirror:sync-from-multdrop
                            {source_client_id : client_id na instalacao de origem}
                            {target_client_id : client_id no sellerapp destino}
                            {--limit=500 : maximo de pedidos a copiar (0 = sem limite)}
                            {--days=30 : janela de dias a copiar}';

    protected $description = '[SEL-357] Copia pedidos da conta espelho de outra instalacao para o sellerapp';

    /** ID da marketplace_account espelho no sellerapp (readonly) */
    private int $mirrorAccountId = 1569;

    public function handle(): int
    {
        $srcClientId = (int) $this->argument('source_client_id');
        $dstClientId = (int) $this->argument('target_client_id');
        $limit       = (int) $this->option('limit');
        $days        = (int) $this->option('days');

        $this->info("[SEL-357 MirrorSync] source_client_id={$srcClientId} target_client_id={$dstClientId} days={$days} limit={$limit}");

        // Verificar conta espelho existe e e readonly
        $mirror = DB::connection('mysql')
            ->table('marketplace_accounts')
            ->where('id', $this->mirrorAccountId)
            ->where('client_id', $dstClientId)
            ->where('mirror_mode', 'readonly')
            ->first();

        if (! $mirror) {
            $this->error('[SEL-357] Conta espelho nao encontrada ou nao e readonly. Abortar.');
            return self::FAILURE;
        }

        $this->info("[OK] Conta espelho confirmada: account_id={$this->mirrorAccountId} shop_id={$mirror->shop_id}");

        // Copiar orders
        $copied   = $this->syncOrders($srcClientId, $dstClientId, $days, $limit);
        $this->info("[OK] Orders copiados: {$copied}");

        // Copiar client_products (catalogo da Victoria)
        $products = $this->syncClientProducts($srcClientId, $dstClientId);
        $this->info("[OK] Produtos copiados: {$products}");

        $this->info('[SEL-357 MirrorSync] Concluido.');

        return self::SUCCESS;
    }

    private function syncOrders(int $srcClient, int $dstClient, int $days, int $limit): int
    {
        $this->info('[...] Buscando orders no multdrop...');

        $srcPdo = new \PDO(
            'mysql:host=127.0.0.1;port=3307;dbname=multdropapp_production;charset=utf8mb4',
            'multdropapp',
            'mdAppPwd_2026_aF5bH8jK4eD3sQ7w',
            [\PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION]
        );

        $since = now()->subDays($days)->toDateTimeString();

        $sql = "SELECT * FROM orders
                WHERE client_id = :client_id
                  AND created_at >= :since
                ORDER BY created_at DESC";

        if ($limit > 0) {
            $sql .= " LIMIT {$limit}";
        }

        $stmt = $srcPdo->prepare($sql);
        $stmt->execute([':client_id' => $srcClient, ':since' => $since]);
        $rows = $stmt->fetchAll(\PDO::FETCH_ASSOC);

        $this->info("[...] Encontrados " . count($rows) . " orders. Copiando...");

        $copied   = 0;
        $skipped  = 0;
        $dstPdo   = DB::connection('mysql')->getPdo();

        $existingIds = DB::connection('mysql')
            ->table('orders')
            ->where('client_id', $dstClient)
            ->where('mirror_source_backend', 'multdrop')
            ->pluck('mirror_source_order_id')
            ->flip()
            ->all();

        foreach ($rows as $row) {
            $srcId = $row['id'];

            // Idempotente: pular se ja copiado
            if (isset($existingIds[$srcId])) {
                $skipped++;
                continue;
            }

            $this->insertMirrorOrder($dstPdo, $row, $dstClient);
            $copied++;
        }

        $this->info("[OK] Orders: {$copied} copiados, {$skipped} ja existiam.");

        return $copied;
    }

    private function insertMirrorOrder(\PDO $pdo, array $row, int $dstClient): void
    {
        // Mascarar dados LGPD antes de gravar (seguranca extra em repouso)
        $maskedName    = 'Cliente #' . substr((string) $row['id'], -3);
        $maskedEmail   = 'cliente' . $row['id'] . '@demo.local';
        $maskedPhone   = '(00) 90000-0000';
        $maskedDoc     = '000.000.000-00';
        $maskedAddress = json_encode([
            'street'      => 'Rua Demonstracao',
            'number'      => rand(100, 999),
            'complement'  => '',
            'district'    => 'Bairro X',
            'city'        => 'Cidade X',
            'state'       => 'UF',
            'zip_code'    => '00000-000',
            'country'     => 'BR',
        ]);

        $stmt = $pdo->prepare("
            INSERT INTO orders (
                client_id, supplier_id, order_number, source,
                marketplace_account_id, marketplace_order_id, shop_id,
                customer_name, customer_email, customer_phone,
                customer_document_type, customer_document_number,
                customer_address,
                subtotal, shipping_cost, marketplace_fee, discount_amount, total,
                status, currency, is_draft,
                marketplace_created_at,
                mirror_source_backend, mirror_source_order_id, mirror_source_client_id,
                created_at, updated_at
            ) VALUES (
                :client_id, :supplier_id, :order_number, :source,
                :marketplace_account_id, :marketplace_order_id, :shop_id,
                :customer_name, :customer_email, :customer_phone,
                :customer_document_type, :customer_document_number,
                :customer_address,
                :subtotal, :shipping_cost, :marketplace_fee, :discount_amount, :total,
                :status, :currency, :is_draft,
                :marketplace_created_at,
                'multdrop', :src_order_id, :src_client_id,
                :created_at, :updated_at
            )
        ");

        $stmt->execute([
            ':client_id'              => $dstClient,
            ':supplier_id'            => null, // FK de outro banco — nao copiar
            ':order_number'           => 'MIR-' . $row['order_number'],
            ':source'                 => $row['source'],
            ':marketplace_account_id' => 1569,  // account espelho
            ':marketplace_order_id'   => $row['marketplace_order_id'],
            ':shop_id'                => $row['shop_id'],
            ':customer_name'          => $maskedName,
            ':customer_email'         => $maskedEmail,
            ':customer_phone'         => $maskedPhone,
            ':customer_document_type' => $row['customer_document_type'],
            ':customer_document_number' => $maskedDoc,
            ':customer_address'       => $maskedAddress,
            ':subtotal'               => $row['subtotal'],
            ':shipping_cost'          => $row['shipping_cost'],
            ':marketplace_fee'        => $row['marketplace_fee'],
            ':discount_amount'        => $row['discount_amount'],
            ':total'                  => $row['total'],
            ':status'                 => $row['status'],
            ':currency'               => $row['currency'] ?? 'BRL',
            ':is_draft'               => $row['is_draft'],
            ':marketplace_created_at' => $row['marketplace_created_at'],
            ':src_order_id'           => $row['id'],
            ':src_client_id'          => $row['client_id'],
            ':created_at'             => $row['created_at'],
            ':updated_at'             => $row['updated_at'],
        ]);
    }

    private function syncClientProducts(int $srcClient, int $dstClient): int
    {
        $this->info('[...] Buscando client_products no multdrop...');

        $srcPdo = new \PDO(
            'mysql:host=127.0.0.1;port=3307;dbname=multdropapp_production;charset=utf8mb4',
            'multdropapp',
            'mdAppPwd_2026_aF5bH8jK4eD3sQ7w',
            [\PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION]
        );

        $stmt = $srcPdo->prepare('SELECT * FROM client_products WHERE client_id = :cid LIMIT 1200');
        $stmt->execute([':cid' => $srcClient]);
        $rows = $stmt->fetchAll(\PDO::FETCH_ASSOC);

        $this->info("[...] Encontrados " . count($rows) . " client_products.");

        // Pegar SKUs ja existentes (idempotente)
        $existingSkus = DB::connection('mysql')
            ->table('client_products')
            ->where('client_id', $dstClient)
            ->where('mirror_source_backend', 'multdrop')
            ->pluck('mirror_source_product_id')
            ->flip()
            ->all();

        $copied  = 0;
        $skipped = 0;
        $dstPdo  = DB::connection('mysql')->getPdo();

        foreach ($rows as $row) {
            if (isset($existingSkus[$row['id']])) {
                $skipped++;
                continue;
            }

            try {
                $stmt2 = $dstPdo->prepare("
                    INSERT INTO client_products (
                        client_id, product_id, product_variation_id,
                        supplier_product_sku, custom_sku, custom_title,
                        custom_price, custom_brand, custom_model,
                        mirror_source_backend, mirror_source_product_id,
                        created_at, updated_at
                    ) VALUES (
                        :client_id, :product_id, :product_variation_id,
                        :supplier_product_sku, :custom_sku, :custom_title,
                        :custom_price, :custom_brand, :custom_model,
                        'multdrop', :src_id,
                        :created_at, :updated_at
                    )
                ");

                $stmt2->execute([
                    ':client_id'            => $dstClient,
                    ':product_id'           => null, // FK de outro banco — nao copiar
                    ':product_variation_id' => null, // FK de outro banco — nao copiar
                    ':supplier_product_sku' => $row['supplier_product_sku'] ?? null,
                    ':custom_sku'           => $row['custom_sku'] ?? null,
                    ':custom_title'         => $row['custom_title'] ?? null,
                    ':custom_price'         => $row['custom_price'] ?? 0,
                    ':custom_brand'         => $row['custom_brand'] ?? null,
                    ':custom_model'         => $row['custom_model'] ?? null,
                    ':src_id'               => $row['id'],
                    ':created_at'           => $row['created_at'],
                    ':updated_at'           => $row['updated_at'],
                ]);

                $copied++;
            } catch (\Exception $e) {
                // Skip silencioso — produto pode ter constraint unica ou coluna divergente
                $this->line("  [skip] client_product id={$row['id']}: " . $e->getMessage());
                $skipped++;
            }
        }

        $this->info("[OK] Produtos: {$copied} copiados, {$skipped} pulados.");

        return $copied;
    }
}
