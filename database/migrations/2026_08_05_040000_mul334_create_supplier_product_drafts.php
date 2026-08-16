<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * MUL-334 fase 2 — produto novo do ERP entra como RASCUNHO, fora de products.
 *
 * Quando o fornecedor compra, a NF de entrada cria o produto no Bling sem preco, sem foto e
 * sem prefixo de deposito. O sync trazia isso direto para products e duplicava o catalogo:
 * 48 duplicatas por execucao, a cada 6h (corrigido em MUL-331, mas so o prefixo).
 *
 * Tabela propria e nao products.is_draft: registro dentro de products VAZA — entra na
 * contagem do catalogo, e empurrado pela federacao, aparece na busca, pode receber anuncio.
 * Em 04-05/08/2026 foram limpos 3.812 produtos indevidos no hub e 479 orfaos na WL,
 * exatamente por esse tipo de vazamento. Com is_draft, toda leitura de produto passaria a
 * depender de um filtro novo; uma esquecida devolve o problema.
 *
 * O fornecedor conclui o cadastro definindo sku_base (CAMPO EM BRANCO, decisao do Ruan em
 * 05/08 — sem sugestao automatica), preco por catalogo e imagem, e publica manualmente.
 * A publicacao cria as linhas em products, uma por deposito, ja com erp_sku preenchido:
 * o mapeamento nasce explicito em vez de derivado.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('supplier_product_drafts', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('supplier_id');

            // identidade no ERP
            $table->string('erp_sku', 100);
            $table->string('erp_product_id', 40)->nullable();   // id do produto no Bling
            $table->char('erp_formato', 1)->nullable();         // S | V | E

            // dados que vem do Bling e o fornecedor nao precisa redigitar
            $table->string('name', 255)->nullable();
            $table->text('description')->nullable();
            $table->decimal('erp_cost', 12, 2)->nullable();     // precoCusto
            $table->decimal('erp_price', 12, 2)->nullable();    // preco
            $table->string('gtin', 20)->nullable();
            $table->string('ncm', 20)->nullable();
            $table->decimal('weight_kg', 10, 3)->nullable();
            $table->json('dimensions')->nullable();
            $table->json('images')->nullable();                 // midia.imagens do Bling
            $table->integer('erp_stock')->nullable();

            // o que o fornecedor preenche. sku_base NASCE VAZIO de proposito.
            $table->string('sku_base', 100)->nullable();
            $table->json('prices_by_supplier')->nullable();     // {"30": 10.59, "157": 3.75}
            $table->string('image_url', 500)->nullable();

            // novo -> em_edicao -> publicado | descartado
            $table->string('status', 12)->default('novo');
            $table->timestamp('first_seen_at')->nullable();     // 1a vez que o sync o achou
            $table->timestamp('published_at')->nullable();
            $table->unsignedBigInteger('published_by')->nullable();
            $table->json('published_product_ids')->nullable();  // linhas criadas em products
            $table->text('notes')->nullable();

            $table->timestamps();

            $table->unique(['supplier_id', 'erp_sku'], 'spd_supplier_erp_sku_unique');
            $table->index(['supplier_id', 'status'], 'spd_supplier_status_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('supplier_product_drafts');
    }
};
