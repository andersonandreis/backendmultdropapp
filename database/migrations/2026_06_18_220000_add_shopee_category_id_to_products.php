<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Adiciona shopee_category_id na tabela products.
     * O campo category_id existente e FK para categories (interno).
     * shopee_category_id e o ID de categoria da Shopee Open Platform
     * e deve ser uma leaf category (sem filhos) exigida pela API Shopee.
     */
    public function up(): void
    {
        Schema::table('products', function (Blueprint $table) {
            if (! Schema::hasColumn('products', 'shopee_category_id')) {
                $table->unsignedBigInteger('shopee_category_id')->nullable()->after('shopee_model_id');
                $table->index('shopee_category_id');
            }
        });
    }

    public function down(): void
    {
        Schema::table('products', function (Blueprint $table) {
            if (Schema::hasColumn('products', 'shopee_category_id')) {
                $table->dropIndex(['shopee_category_id']);
                $table->dropColumn('shopee_category_id');
            }
        });
    }
};
