<?php

namespace App\Services\Catalog;

use App\Models\Product;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;

/**
 * MUL-334 fase 2 — publicacao de rascunho de produto do ERP.
 *
 * O rascunho nasce do sync do Bling (BlingProductSync::registrarRascunho) quando chega um
 * codigo que nao existe em nenhum catalogo do grupo. Ele NAO e produto: fica fora de
 * products justamente para nao vazar para catalogo, federacao, busca e anuncio.
 *
 * Publicar e o ato do fornecedor de transformar rascunho em produto. Ele define o SKU da
 * plataforma, escolhe em quais catalogos o produto entra e o preco de cada um. So entao as
 * linhas nascem em products, ja com erp_sku preenchido — o mapeamento nasce EXPLICITO em vez
 * de derivado por regra (so 814 de 4.658 SKUs casariam por regra, e ha 100 colisoes).
 */
class SupplierProductDraftService
{
    /**
     * Catalogos do grupo de um fornecedor: a raiz e os filhos.
     * Um fornecedor pode ter N catalogos — MultDrop tem 2 e pode ter 10.
     *
     * @return array<int, object>  id, slug, display_name, legacy_id
     */
    public function catalogosDoGrupo(int $supplierId): array
    {
        $raiz = (int) (DB::table('suppliers')->where('id', $supplierId)->value('parent_supplier_id') ?: $supplierId);

        return DB::table('suppliers')
            ->where(fn ($q) => $q->where('id', $raiz)->orWhere('parent_supplier_id', $raiz))
            ->where('is_active', true)
            ->orderByRaw('CASE WHEN id = ? THEN 0 ELSE 1 END', [$raiz])
            ->get(['id', 'slug', 'display_name', 'company_name', 'legacy_id'])
            ->all();
    }

    /**
     * O SKU final daquele produto num catalogo: prefixo do deposito + base.
     * O prefixo vem de suppliers.legacy_id (498 = matriz, 773 = filial) — heranca do
     * Goolhub, nao existe no Bling. Catalogo que nunca usou prefixo fica com a base pura.
     */
    public function skuDoCatalogo(int $supplierId, string $skuBase): string
    {
        $legacyId = DB::table('suppliers')->where('id', $supplierId)->value('legacy_id');
        if (! $legacyId) {
            return $skuBase;
        }

        $prefixo = 'D' . $legacyId . '-';
        $usaPrefixo = Product::where('supplier_id', $supplierId)->where('sku', 'like', $prefixo . '%')->exists();

        return $usaPrefixo ? $prefixo . $skuBase : $skuBase;
    }

    /**
     * Confere o que impediria a publicacao. Devolve lista de problemas — vazia significa
     * que pode publicar.
     *
     * @param  array<int, float>  $precos  supplier_id => preco
     * @return array<int, string>
     */
    public function validar(object $draft, string $skuBase, array $precos): array
    {
        $erros = [];
        $skuBase = trim($skuBase);

        if ($skuBase === '') {
            $erros[] = 'Informe o SKU da plataforma.';
            return $erros;   // sem SKU nao da para checar o resto
        }
        if (! preg_match('/^[A-Za-z0-9._\/+-]+$/', $skuBase)) {
            $erros[] = 'O SKU aceita apenas letras, numeros e os sinais . _ / + -';
        }
        if (preg_match('/^D\d+-/', $skuBase)) {
            $erros[] = 'Nao inclua o prefixo do deposito no SKU — ele e aplicado por catalogo.';
        }
        if (empty($precos)) {
            $erros[] = 'Escolha ao menos um catalogo.';
        }

        $idsDoGrupo = array_map(fn ($c) => (int) $c->id, $this->catalogosDoGrupo((int) $draft->supplier_id));

        foreach ($precos as $supplierId => $preco) {
            $supplierId = (int) $supplierId;

            if (! in_array($supplierId, $idsDoGrupo, true)) {
                $erros[] = "O catalogo {$supplierId} nao pertence a este fornecedor.";
                continue;
            }
            // formato V e pai de variacao: nao se vende, quem vende sao as filhas.
            if ($draft->erp_formato !== 'V' && (float) $preco <= 0) {
                $erros[] = "Informe o preco para o catalogo {$supplierId}.";
            }

            // products_sku_unique e GLOBAL (NOV-152): colide com qualquer supplier.
            $sku = $this->skuDoCatalogo($supplierId, $skuBase);
            $dono = Product::where('sku', $sku)->first(['id', 'supplier_id']);
            if ($dono) {
                $erros[] = "O SKU {$sku} ja existe (produto {$dono->id}, fornecedor {$dono->supplier_id}).";
            }
        }

        return $erros;
    }

    /**
     * Publica o rascunho: cria uma linha em products por catalogo escolhido.
     *
     * @param  array<int, float>  $precos  supplier_id => preco
     * @throws ValidationException
     */
    public function publicar(int $draftId, string $skuBase, array $precos, ?string $imageUrl = null, ?int $userId = null): array
    {
        $draft = DB::table('supplier_product_drafts')->where('id', $draftId)->first();

        if (! $draft) {
            throw ValidationException::withMessages(['draft' => 'Rascunho nao encontrado.']);
        }
        if ($draft->status === 'publicado') {
            throw ValidationException::withMessages(['draft' => 'Este rascunho ja foi publicado.']);
        }

        $skuBase = trim($skuBase);
        $erros = $this->validar($draft, $skuBase, $precos);
        if ($erros) {
            throw ValidationException::withMessages(['draft' => $erros]);
        }

        $dimensoes = json_decode((string) $draft->dimensions, true) ?: [];
        $imagens   = json_decode((string) $draft->images, true) ?: [];
        $capa      = $imageUrl ?: ($imagens[0] ?? null);

        $criados = [];

        DB::transaction(function () use ($draft, $skuBase, $precos, $capa, $dimensoes, $userId, &$criados) {
            foreach ($precos as $supplierId => $preco) {
                $supplierId = (int) $supplierId;
                $sku = $this->skuDoCatalogo($supplierId, $skuBase);

                $produto = Product::create([
                    'supplier_id'           => $supplierId,
                    'sku'                   => $sku,
                    'name'                  => $draft->name ?: $sku,
                    'description'           => $draft->description,
                    'price'                 => (float) $preco,
                    'cost'                  => $draft->erp_cost,
                    'gtin'                  => $draft->gtin,
                    'ncm'                   => $draft->ncm,
                    'weight_kg'             => $draft->weight_kg,
                    'height_cm'             => $dimensoes['altura'] ?? null,
                    'width_cm'              => $dimensoes['largura'] ?? null,
                    'length_cm'             => $dimensoes['profundidade'] ?? null,
                    // MUL-334: erp_sku vem do rascunho e o mapeamento e MANUAL — quem decidiu
                    // o vinculo entre o codigo do ERP e o SKU da plataforma foi o fornecedor.
                    'erp_sku'               => $draft->erp_sku,
                    'erp_formato'           => $draft->erp_formato,
                    'erp_map_origem'        => 'manual',
                    'erp_map_confirmado_em' => now(),
                    // formato V e pai de variacao: entra desativado, nao se vende.
                    'is_active'             => $draft->erp_formato !== 'V',
                ]);

                if ($capa) {
                    DB::table('product_media')->insert([
                        'product_id' => $produto->id,
                        'url'        => $capa,
                        'type'       => 'image',
                        'position'   => 0,
                        'created_at' => now(),
                        'updated_at' => now(),
                    ]);
                }

                $criados[] = ['supplier_id' => $supplierId, 'product_id' => $produto->id, 'sku' => $sku];
            }

            DB::table('supplier_product_drafts')->where('id', $draft->id)->update([
                'status'                => 'publicado',
                'sku_base'              => $skuBase,
                'prices_by_supplier'    => json_encode($precos),
                'image_url'             => $capa,
                'published_at'          => now(),
                'published_by'          => $userId,
                'published_product_ids' => json_encode(array_column($criados, 'product_id')),
                'updated_at'            => now(),
            ]);
        });

        Log::info('[MUL-334] rascunho publicado', [
            'draft_id' => $draft->id,
            'erp_sku'  => $draft->erp_sku,
            'sku_base' => $skuBase,
            'criados'  => $criados,
        ]);

        return $criados;
    }

    /**
     * Descarta o rascunho. Nao volta a aparecer nas proximas execucoes do sync.
     */
    public function descartar(int $draftId, ?string $motivo = null): void
    {
        DB::table('supplier_product_drafts')->where('id', $draftId)->update([
            'status'     => 'descartado',
            'notes'      => $motivo,
            'updated_at' => now(),
        ]);
    }
}
