<?php

namespace App\Services;

use App\Models\Product;
use App\Models\ProductContentBank;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * SEL-XXX (04/08): banco de titulo/descricao/bullets com rotacao entre clientes.
 *
 * Modelo (decisao do Ruan 04/08):
 * - Repetir titulo ENTRE CLIENTES e permitido ate um teto (max_uses).
 * - O que NAO pode e um LOTE NOVO de geracao repetir um titulo que ja existe
 *   no banco daquele produto (anti-duplicata e CONTRA O PROPRIO BANCO, nao
 *   entre clientes).
 * - Cliente consome do banco em tempo real (serve()); geracao roda em lote
 *   via comando de reabastecimento (ReplenishProductContentBankCommand).
 * - Registro que bate o teto e "aposentado" (retired_at preenchido) -- fica
 *   guardado pra sempre, nunca deletado, so para de ser servido.
 */
class ProductContentBankService
{
    /**
     * Identidade do produto que serve a TODOS os WLs federados do hub.
     * hub_product_id e o ID canonico em hubaiapp.products (federation_source
     * hubai). GTIN/EAN seriam a chave ideal (universal, alem do ecossistema
     * HubAI) mas estao 0% preenchidos nas copias federadas nos WLs hoje --
     * ver nota na migration.
     */
    public function productKeyFor(Product $product): string
    {
        if (! empty($product->hub_product_id)) {
            return 'hub:' . $product->hub_product_id;
        }

        return 'local:' . $product->supplier_id . ':' . $product->sku;
    }

    /**
     * Serve o registro disponivel com MENOR times_used pra este produto
     * (rotacao) e incrementa o uso. Retorna null se o banco estiver vazio
     * ou todos os registros ja aposentados (bateram max_uses).
     * Lock pessimista pra evitar duas requisicoes simultaneas servirem o
     * mesmo registro sem contar o uso corretamente.
     *
     * @return array{title:string,description:?string,bullet_points:?array}|null
     */
    public function serve(Product $product): ?array
    {
        $productKey = $this->productKeyFor($product);

        return DB::transaction(function () use ($productKey) {
            $entry = ProductContentBank::where('product_key', $productKey)
                ->whereNull('retired_at')
                ->whereColumn('times_used', '<', 'max_uses')
                ->orderBy('times_used', 'asc')
                ->orderBy('id', 'asc')
                ->lockForUpdate()
                ->first();

            if (! $entry) {
                return null;
            }

            $entry->times_used++;
            if ($entry->times_used >= $entry->max_uses) {
                $entry->retired_at = now();
            }
            $entry->save();

            return [
                'title'         => $entry->title,
                'description'   => $entry->description,
                'bullet_points' => $entry->bullet_points,
            ];
        });
    }

    /** Quantos registros ainda disponiveis (nao aposentados) pra este produto. */
    public function availableCount(string $productKey): int
    {
        return ProductContentBank::where('product_key', $productKey)
            ->whereNull('retired_at')
            ->whereColumn('times_used', '<', 'max_uses')
            ->count();
    }

    /**
     * Todos os titulos JA no banco pra este produto (disponiveis + aposentados
     * -- aposentado tambem conta pra anti-duplicata, ele existiu e nao pode
     * ser regerado igual). Usado tanto pra checar colisao quanto pra injetar
     * no prompt de um novo lote.
     */
    public function allTitlesForKey(string $productKey): array
    {
        return ProductContentBank::where('product_key', $productKey)
            ->orderByDesc('id')
            ->pluck('title')
            ->all();
    }

    protected function normalize(string $text): string
    {
        $text = mb_strtolower(trim($text));
        return preg_replace('/\s+/', ' ', $text) ?? $text;
    }

    /** Colide (identico ou >=90% similar) com algo ja existente no banco pra esta chave. */
    public function collidesWithBank(string $productKey, string $title): bool
    {
        $norm = $this->normalize($title);

        foreach ($this->allTitlesForKey($productKey) as $existing) {
            $existingNorm = $this->normalize($existing);
            if ($existingNorm === $norm) {
                return true;
            }
            similar_text($norm, $existingNorm, $pct);
            if ($pct >= 90.0) {
                return true;
            }
        }

        return false;
    }

    /**
     * Insere um novo registro no banco SE nao colidir com o que ja existe pra
     * esta chave. Retorna true se inseriu, false se descartou por duplicata.
     */
    public function store(
        string $productKey,
        string $title,
        ?string $description,
        ?array $bulletPoints,
        string $sourceBatch,
        int $maxUses = 5
    ): bool {
        if ($this->collidesWithBank($productKey, $title)) {
            Log::info('[ContentBank] titulo descartado -- colide com o banco', [
                'product_key' => $productKey,
                'title'       => $title,
            ]);
            return false;
        }

        ProductContentBank::create([
            'product_key'   => $productKey,
            'title'         => $title,
            'description'   => $description,
            'bullet_points' => $bulletPoints,
            'times_used'    => 0,
            'max_uses'      => $maxUses,
            'source_batch'  => $sourceBatch,
        ]);

        return true;
    }
}
