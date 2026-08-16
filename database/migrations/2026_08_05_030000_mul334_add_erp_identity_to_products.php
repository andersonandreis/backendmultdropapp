<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * MUL-334 fase 1 — identidade do produto no ERP.
 *
 * O MultDrop tem o MESMO produto fisico em dois catalogos (D498- matriz e D773- filial),
 * com precos diferentes e estoque que deveria ser o mesmo. Nao existia nada no banco ligando
 * D498-X a D773-X: a identidade era o SKU, e o prefixo fazia de cada deposito um produto.
 * Medido em 05/08/2026: 268 de 409 pares com estoque DIFERENTE, quase sempre D773 = 0.
 *
 * erp_sku e o codigo do produto no Bling, sem prefixo — a identidade fisica. Um erp_sku
 * reune N linhas de catalogo.
 *
 * Nome erp_sku e nao erp_code: a base usa _code para o que NAO identifica produto
 * (barcode, tracking_code, zip_code) e _sku para o que identifica (custom_sku, service_sku).
 *
 * NAO existe erp_deposit: 498/773 sao suppliers.legacy_id, herança do Goolhub. O Bling tem
 * dois depositos proprios (Estoque Multdrop e Produtos com Defeito) e nenhum e 498 ou 773.
 * O catalogo ja sai de supplier_id.
 *
 * products.sku NAO muda: 8.364 anuncios ativos dependem dele, e em 516 o custom_sku ja
 * difere do sku do produto.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('products', function (Blueprint $table) {
            // codigo do produto no ERP (Bling), sem prefixo de deposito
            $table->string('erp_sku', 100)->nullable()->after('sku');

            // natureza do registro, direto do Bling. Conferido em 05/08/2026 nos 26 V e 11 E
            // do catalogo: separacao perfeita, nenhum produto tem variacoes E componentes.
            //   S = simples, vendavel
            //   V = pai de variacao, NAO vendavel (as filhas e que vendem)
            //   E = com estrutura (kit), tipoEstoque=V, estoque calculado pelos componentes
            $table->char('erp_formato', 1)->nullable()->after('erp_sku');

            // quando a linha e filha de variacao, o erp_sku do pai
            $table->string('erp_parent_sku', 100)->nullable()->after('erp_formato');

            // procedencia do mapeamento — importa porque so 814 de 4.658 SKUs casam por
            // regra limpa, e ha 100 colisoes. bling = codigo confirmado na API;
            // par = deduzido do par D498/D773; manual = confirmado por humano.
            $table->string('erp_map_origem', 12)->nullable()->after('erp_parent_sku');
            $table->timestamp('erp_map_confirmado_em')->nullable()->after('erp_map_origem');

            $table->index(['supplier_id', 'erp_sku'], 'products_supplier_erp_sku_index');
            $table->index('erp_sku', 'products_erp_sku_index');
        });
    }

    public function down(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->dropIndex('products_supplier_erp_sku_index');
            $table->dropIndex('products_erp_sku_index');
            $table->dropColumn([
                'erp_sku',
                'erp_formato',
                'erp_parent_sku',
                'erp_map_origem',
                'erp_map_confirmado_em',
            ]);
        });
    }
};
