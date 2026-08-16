<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * NOV-171-A — M-171-02
 * Adiciona hub_product_id em products — SOMENTE nos WLs (multdrop, fornecefy, mestoredrop).
 * Referencia numerica ao products.id do hubaiapp (NOT uma FK cruzada — bancos separados).
 * Hub nao precisa desta coluna (o hub EH a fonte de verdade).
 */
return new class extends Migration
{
    public function up(): void
    {
        // hub_product_id nao eh necessario no hubaiapp (hub EH a fonte de verdade)
        // Nos WLs, eh a referencia ao produto pai no hub — necessario para dedup
        if (Schema::hasColumn('products', 'hub_product_id')) {
            return;
        }

        Schema::table('products', function (Blueprint $table) {
            $table->unsignedBigInteger('hub_product_id')
                ->nullable()
                ->after('id')
                ->comment('ID do produto pai em api.hubai.io/products. Nao eh FK cruzada — bancos separados. Usado para dedup e sincronizacao bidirecional.');

            $table->index('hub_product_id', 'idx_products_hub_product_id');
        });
    }

    public function down(): void
    {
        if (!Schema::hasColumn('products', 'hub_product_id')) {
            return;
        }

        Schema::table('products', function (Blueprint $table) {
            $table->dropIndex('idx_products_hub_product_id');
            $table->dropColumn('hub_product_id');
        });
    }
};
