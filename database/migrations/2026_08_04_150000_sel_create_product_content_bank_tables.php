<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * SEL-XXX (04/08): banco de conteudo IA (titulo/descricao/bullets) com rotacao.
 *
 * Decisao do Ruan (04/08): repetir titulo ENTRE CLIENTES e permitido ate um teto
 * (max_uses) -- o que NAO pode e um LOTE NOVO repetir um titulo que ja existe no
 * banco daquele produto. Cliente consome do banco (instantaneo, sem custo no
 * clique); geracao via IA roda em lote/background (reabastecimento).
 *
 * product_key: identidade do produto que serve a TODOS os WLs federados do hub.
 * Hoje 644/645 produtos do seller.global tem hub_product_id preenchido
 * (federation_source=hubai) -- e o ID canonico em hubaiapp.products, MELHOR
 * chave real disponivel hoje (GTIN/EAN estao 0% preenchidos nas copias
 * federadas nos WLs, apesar de 83%/91% preenchidos na origem/hub -- gap do
 * payload do FederationPushProductJob que nao envia gtin/ean; nao mexido aqui).
 * product_key = 'hub:{hub_product_id}' quando existe, senao 'local:{supplier_id}:{sku}'.
 *
 * NOTA DE ESCOPO: esta tabela vive em sellerapp_production (local ao
 * seller.global) por enquanto -- e o banco de dados onde o job/servico ja
 * roda e onde temos acesso/teste validado hoje. Para ficar DE FATO
 * compartilhada entre TODOS os WLs (multdrop/fornecefy/mestoredrop/dropksr)
 * a forma correta e centralizar em hubaiapp (hub) ou expor via API -- decisao
 * de infra maior, fora do escopo desta mudanca pontual. Reportado ao Ruan.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('product_content_bank', function (Blueprint $table) {
            $table->id();
            $table->string('product_key', 191); // 'hub:{id}' ou 'local:{supplier_id}:{sku}'
            $table->string('title', 100);
            $table->text('description')->nullable();
            $table->json('bullet_points')->nullable();
            $table->unsignedInteger('times_used')->default(0);
            $table->unsignedInteger('max_uses')->default(5);
            $table->timestamp('retired_at')->nullable(); // bateu o teto -- so marca, nunca deleta
            $table->string('source_batch', 100)->nullable(); // identificador da rodada de geracao
            $table->timestamps();

            $table->index(['product_key', 'retired_at', 'times_used'], 'idx_bank_serve_lookup');
            $table->index('source_batch');
        });

        // Ponteiro de cobertura do catalogo -- ordem = mesma do catalogo do
        // cliente (TenantApi/V1/ProductController@index: Product::orderBy('id')).
        // Guarda ate onde o reabastecimento em lote ja cobriu, pra proxima
        // rodada continuar sem pular nem repetir produto.
        Schema::create('product_content_bank_cursors', function (Blueprint $table) {
            $table->id();
            $table->string('name', 100)->unique(); // ex: 'catalog_id_asc'
            $table->unsignedBigInteger('last_product_id')->default(0);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('product_content_bank_cursors');
        Schema::dropIfExists('product_content_bank');
    }
};
