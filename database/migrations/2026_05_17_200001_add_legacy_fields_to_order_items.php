<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('order_items', function (Blueprint $table) {
            if (! Schema::hasColumn('order_items', 'product_image')) {
                $table->text('product_image')->nullable()->after('listing_type_id')
                    ->comment('URL da imagem do produto vinda do legado');
            }
            if (! Schema::hasColumn('order_items', 'legacy_sku_pai_id')) {
                $table->unsignedInteger('legacy_sku_pai_id')->nullable()->after('product_image')->index()
                    ->comment('id_sku_pai da tabela pedidos_produtos do legado');
            }
        });
    }

    public function down(): void
    {
        Schema::table('order_items', function (Blueprint $table) {
            if (Schema::hasColumn('order_items', 'product_image')) {
                $table->dropColumn('product_image');
            }
            if (Schema::hasColumn('order_items', 'legacy_sku_pai_id')) {
                $table->dropColumn('legacy_sku_pai_id');
            }
        });
    }
};
