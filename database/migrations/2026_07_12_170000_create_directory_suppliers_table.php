<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * SEL-048: Tabela de diretorio de fornecedores (conteudo editorial).
     * NAO confundir com a tabela suppliers (fornecedores operacionais de dropshipping).
     * Esta tabela e um catalogo/diretorio sem TenantSupplierScope.
     */
    public function up(): void
    {
        Schema::create('directory_suppliers', function (Blueprint $table) {
            $table->id();

            // Identidade
            $table->string('name');
            $table->string('slug')->unique();
            $table->json('categories')->nullable()->comment('Array de categorias');
            $table->text('description')->nullable();
            $table->string('location')->nullable()->comment('Cidade/UF ou regiao ex.: Bras-SP');

            // Contatos estruturados (para agente de outreach)
            $table->string('email')->nullable();
            $table->string('phone')->nullable()->comment('Telefone fixo/celular nao-WhatsApp');
            $table->string('whatsapp')->nullable()->comment('Numero WhatsApp +55DDDNUMERO');
            $table->string('instagram')->nullable()->comment('Handle sem arroba ex.: modasfulana');
            $table->json('other_socials')->nullable()->comment('{facebook:null,tiktok:null,youtube:null,telegram:null}');

            // Presenca digital
            $table->string('site')->nullable();
            $table->string('catalog_url')->nullable()->comment('Link de catalogo/drive/lista de precos');
            $table->json('marketplaces')->nullable()->comment('Onde vende: shopee, mercadolivre, etc.');

            // Condicoes comerciais
            $table->string('min_order')->nullable()->comment('Texto livre ex.: R$ 300 ou 12 pecas');
            $table->string('shipping_info')->nullable()->comment('Transportadora/correios/onibus etc.');
            $table->text('commercial_terms')->nullable()->comment('Pagamento, desconto atacado, CNPJ exigido etc.');

            // Midia
            $table->string('logo_url')->nullable();
            $table->string('cover_url')->nullable();

            // Metadados de origem
            $table->json('sources')->nullable()->comment('Fontes de origem (PDFs, Supabase, etc.)');
            $table->text('notes')->nullable()->comment('Observacoes internas do editorial');

            // Gate por plano (preparado, hoje null = liberado para todos)
            $table->unsignedBigInteger('min_plan_id')->nullable()
                ->comment('Se nao-null: apenas usuarios com plano >= este id veem contatos');

            // Controle
            $table->boolean('verified')->default(false)->comment('Fornecedor verificado pela equipe');
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            // Indices
            $table->index('verified');
            $table->index('is_active');
        });

        // FULLTEXT index para busca livre em name e description
        DB::statement('ALTER TABLE directory_suppliers ADD FULLTEXT ft_name_desc (name, description)');
    }

    public function down(): void
    {
        Schema::dropIfExists('directory_suppliers');
    }
};
