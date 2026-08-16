<?php

namespace App\Services\Integrations\Erps\Bling;

use App\Models\ErpAccount;
use App\Models\MarketplaceAccount;
use App\Models\Product;
use Illuminate\Support\Facades\Log;

/**
 * Fase 4: Atualiza estoque do HubAI -> Bling.
 * Quando o estoque muda no HubAI, envia a atualizacao pro Bling.
 */
class BlingStockPush
{
    public function __construct(
        protected BlingApiClient $client
    ) {}

    /**
     * Envia atualizacao de estoque de um produto especifico pro Bling.
     */
    public function pushProductStock(ErpAccount|MarketplaceAccount $account, Product $product, int $quantity, ?int $depositId = null): bool
    {
        if (!$product->sku) {
            return false;
        }

        try {
            // Primeiro busca o produto no Bling pelo SKU pra pegar o ID
            $blingProductId = $this->findBlingProductId($account, $product->sku);

            if (!$blingProductId) {
                Log::warning("[BlingStockPush] Product not found in Bling", ["sku" => $product->sku]);
                return false;
            }

            // Busca deposito padrao se nao fornecido
            if (!$depositId) {
                $depositId = $this->getDefaultDepositId($account);
            }

            if (!$depositId) {
                Log::warning("[BlingStockPush] No deposit found", ["sku" => $product->sku]);
                return false;
            }

            // Monta payload de atualizacao de estoque
            $payload = [
                "produto" => ["id" => $blingProductId],
                "deposito" => ["id" => $depositId],
                "quantidade" => $quantity,
                "operacao" => "B", // B = Balanco (seta o valor absoluto)
            ];

            $this->client->post($account, "/estoques", $payload);

            Log::info("[BlingStockPush] Stock updated", [
                "sku" => $product->sku,
                "qty" => $quantity,
                "bling_id" => $blingProductId,
            ]);

            return true;
        } catch (\Throwable $e) {
            Log::error("[BlingStockPush] Failed", [
                "sku" => $product->sku,
                "error" => $e->getMessage(),
            ]);
            return false;
        }
    }

    /**
     * Push estoque de todos os produtos do fornecedor pro Bling.
     */
    public function pushAll(ErpAccount|MarketplaceAccount $account): array
    {
        $stats = ["pushed" => 0, "skipped" => 0, "errors" => 0];

        $products = Product::where("supplier_id", $account->supplier_id)
            ->whereNotNull("sku")
            ->where("sku", "!=", "")
            ->where("is_active", true)
            ->get();

        foreach ($products as $product) {
            $qty = $product->virtual_stock_qty ?? $product->inventory()->sum("quantity");

            if ($qty === null) {
                $stats["skipped"]++;
                continue;
            }

            $success = $this->pushProductStock($account, $product, $qty);
            $stats[$success ? "pushed" : "errors"]++;

            usleep(350000); // rate limit 3/s
        }

        return $stats;
    }

    /**
     * Busca o ID do produto no Bling pelo SKU (codigo).
     */
    protected function findBlingProductId(ErpAccount|MarketplaceAccount $account, string $sku): ?int
    {
        try {
            $response = $this->client->get($account, "/produtos", [
                "codigo" => $sku,
                "limite" => 1,
            ]);

            $products = $response["data"] ?? [];
            return $products[0]["id"] ?? null;
        } catch (\Throwable $e) {
            return null;
        }
    }

    /**
     * Busca o ID do deposito padrao no Bling.
     * Faz cache em memoria pra nao consultar a cada produto.
     */
    protected ?int $cachedDepositId = null;

    protected function getDefaultDepositId(ErpAccount|MarketplaceAccount $account): ?int
    {
        if ($this->cachedDepositId !== null) {
            return $this->cachedDepositId;
        }

        try {
            $response = $this->client->listDeposits($account);
            $deposits = $response["data"] ?? [];

            // Busca o deposito padrao primeiro
            foreach ($deposits as $dep) {
                if (!empty($dep["padrao"])) {
                    $this->cachedDepositId = (int) $dep["id"];
                    return $this->cachedDepositId;
                }
            }

            // Se nao tem padrao, usa o primeiro ativo
            foreach ($deposits as $dep) {
                if (($dep["situacao"] ?? 0) == 1) {
                    $this->cachedDepositId = (int) $dep["id"];
                    return $this->cachedDepositId;
                }
            }
        } catch (\Throwable $e) {
            Log::error("[BlingStockPush] Failed to fetch deposits: " . $e->getMessage());
        }

        return null;
    }
}
