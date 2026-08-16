<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Models\Product;
use App\Models\ErpAccount;
use App\Models\MarketplaceAccount;
use App\Services\Integrations\Erps\Bling\BlingProductSync;
use App\Services\Integrations\Erps\Bling\BlingApiClient;
use App\Observers\ProductObserver;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class SyncBlingImagesCommand extends Command
{
    protected $signature = "products:sync-bling-images
        {--supplier-id= : Supplier ID to sync}
        {--erp-account= : ErpAccount ID (Bling ERP)}
        {--marketplace-account= : MarketplaceAccount ID (Bling Marketplace)}
        {--chunk=50 : Products per batch}
        {--limit= : Max products to process}
        {--dry-run : Simulate without saving}";

    protected $description = "[MUL-064] Backfill product images from Bling API for products without media";

    public function handle(): int
    {
        $supplierId = $this->option("supplier-id");
        $erpAccountId = $this->option("erp-account");
        $marketplaceAccountId = $this->option("marketplace-account");
        $chunk = (int) $this->option("chunk");
        $limit = $this->option("limit") ? (int) $this->option("limit") : null;
        $dryRun = $this->option("dry-run");

        $this->info("[MUL-064] Sync Bling Images iniciado" . ($dryRun ? " [DRY-RUN]" : ""));

        if (!$supplierId && !$erpAccountId && !$marketplaceAccountId) {
            $this->error("Informe --supplier-id, --erp-account ou --marketplace-account");
            return 1;
        }

        ProductObserver::$disableSync = true;

        $accounts = [];

        if ($erpAccountId) {
            $acc = ErpAccount::find($erpAccountId);
            if (!$acc) { $this->error("ErpAccount {$erpAccountId} nao encontrada"); return 1; }
            $accounts[] = ["type" => "erp", "account" => $acc, "supplier_id" => $acc->supplier_id];
        }

        if ($marketplaceAccountId) {
            $acc = MarketplaceAccount::find($marketplaceAccountId);
            if (!$acc) { $this->error("MarketplaceAccount {$marketplaceAccountId} nao encontrada"); return 1; }
            $accounts[] = ["type" => "marketplace", "account" => $acc, "supplier_id" => $acc->supplier->id ?? null];
        }

        if ($supplierId && empty($accounts)) {
            $erps = ErpAccount::where("supplier_id", $supplierId)
                ->where("platform", "bling")
                ->where("is_active", true)
                ->get();
            foreach ($erps as $erp) {
                $accounts[] = ["type" => "erp", "account" => $erp, "supplier_id" => $supplierId];
            }
            if (empty($accounts)) {
                $this->warn("Nenhuma ErpAccount Bling ativa para supplier_id={$supplierId}");
                return 0;
            }
        }

        $totalImported = 0;
        $totalSkipped  = 0;
        $totalErrors   = 0;

        foreach ($accounts as $entry) {
            $account = $entry["account"];
            $sid     = $entry["supplier_id"];
            $type    = $entry["type"];

            $this->info("[MUL-064] Conta: {$account->account_name} ({$type} id={$account->id}, supplier_id={$sid})");

            $query = Product::where("supplier_id", $sid)
                ->whereNotExists(function ($q) {
                    $q->select(DB::raw(1))
                      ->from("product_media")
                      ->whereColumn("product_media.product_id", "products.id");
                })
                ->whereNotNull("sku")
                ->where("sku", "!=", "");

            if ($limit) {
                $query->limit($limit);
            }

            $total = $query->count();
            $this->info("[MUL-064] Produtos sem media: {$total}");

            if ($total === 0) {
                $this->info("[MUL-064] Nenhum produto sem media. Pulando.");
                continue;
            }

            $sync   = app(BlingProductSync::class);
            $client = app(BlingApiClient::class);

            $processed = 0;

            $query->chunkById($chunk, function ($products) use ($client, $sync, $account, $dryRun, &$processed, &$totalImported, &$totalSkipped, &$totalErrors, $total) {
                foreach ($products as $product) {
                    $processed++;
                    $sku = $product->sku;

                    try {
                        $searchResp = $client->get($account, "/produtos", ["codigo" => $sku, "limite" => 1]);
                        usleep(350000);

                        $items = $searchResp["data"] ?? [];
                        if (empty($items)) {
                            $totalSkipped++;
                            continue;
                        }

                        $blingId = $items[0]["id"] ?? null;
                        if (!$blingId) { $totalSkipped++; continue; }

                        $detail = $client->get($account, "/produtos/{$blingId}");
                        usleep(350000);

                        $blingProduct = $detail["data"] ?? [];
                        if (empty($blingProduct)) { $totalSkipped++; continue; }

                        $hasImage = !empty(trim($blingProduct["imagemURL"] ?? ""))
                            || !empty($blingProduct["midia"]["imagens"]["externas"] ?? [])
                            || !empty($blingProduct["midia"]["imagens"]["imagensURL"] ?? []);

                        if (!$hasImage) {
                            $totalSkipped++;
                            if ($processed % 200 === 0) {
                                $this->line("  [{$processed}/{$total}] sem imagem no Bling");
                            }
                            continue;
                        }

                        if ($dryRun) {
                            $this->line("  [dry] SKU={$sku} TEM imagem no Bling");
                            $totalImported++;
                        } else {
                            $sync->saveProductImages($product, $blingProduct);
                            $totalImported++;
                            $this->line("  [ok] SKU={$sku} imagem salva");
                        }

                    } catch (\Throwable $e) {
                        $totalErrors++;
                        $this->warn("  [erro] SKU={$sku}: " . $e->getMessage());
                        Log::warning("[MUL-064] sync-bling-images erro", ["sku" => $sku, "product_id" => $product->id, "error" => $e->getMessage()]);
                        usleep(500000);
                    }
                }
            });
        }

        ProductObserver::$disableSync = false;

        $this->info("");
        $this->info("[MUL-064] Concluido: importados={$totalImported} | sem_imagem={$totalSkipped} | erros={$totalErrors}");

        return 0;
    }
}
