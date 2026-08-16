<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     * Adiciona shopee_item_id e shopee_model_id na tabela products
     * para rastreio de anuncios Shopee por SKU.
     */
    public function up(): void
    {
        Schema::table('products', function (Blueprint $table) {
            if (! Schema::hasColumn('products', 'shopee_item_id')) {
                $table->unsignedBigInteger('shopee_item_id')->nullable()->after('sku');
                $table->index('shopee_item_id');
            }
            if (! Schema::hasColumn('products', 'shopee_model_id')) {
                $table->unsignedBigInteger('shopee_model_id')->nullable()->after('shopee_item_id');
            }
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('products', function (Blueprint $table) {
            if (Schema::hasColumn('products', 'shopee_item_id')) {
                $table->dropIndex(['shopee_item_id']);
                $table->dropColumn('shopee_item_id');
            }
            if (Schema::hasColumn('products', 'shopee_model_id')) {
                $table->dropColumn('shopee_model_id');
            }
        });
    }
};
