<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * NOV-144 — Descontos por catalogo / faixa de quantidade.
 *
 * Tabelas existentes (NAO duplicar):
 *  - supplier_discounts + supplier_discount_tiers (descontos por cliente/segmento)
 *  - platform_discounts + platform_discount_tiers (descontos por volume da plataforma)
 *  - plan_discounts (descontos por plano)
 *
 * Esta tabela cobre o caso especifico do legado: regra de desconto vinculada a
 * um catalogo + faixa de quantidade do mesmo produto, sem precisar criar registro
 * supplier_discount por cliente.
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::create('catalog_discount_rules', function (Blueprint $table) {
            $table->id();
            $table->foreignId('supplier_id')->constrained('suppliers')->cascadeOnDelete();
            $table->unsignedBigInteger('catalog_id')->nullable()->index();
            $table->string('name')->nullable();
            $table->integer('min_qty')->default(1);
            $table->integer('max_qty')->nullable();
            $table->decimal('discount_pct', 5, 2);
            $table->boolean('active')->default(true);
            $table->timestamp('starts_at')->nullable();
            $table->timestamp('ends_at')->nullable();
            $table->timestamps();

            $table->index(['supplier_id', 'catalog_id', 'active']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('catalog_discount_rules');
    }
};
