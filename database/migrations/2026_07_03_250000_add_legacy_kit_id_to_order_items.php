<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * MUL-145: Adiciona legacy_kit_id em order_items para identificar itens de pedido
 * que correspondem a kits do legado. Campo de vinculo read-only.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('order_items', function (Blueprint $table) {
            if (! Schema::hasColumn('order_items', 'legacy_kit_id')) {
                $table->unsignedBigInteger('legacy_kit_id')->nullable()->after('legacy_sku_pai_id');
                $table->index('legacy_kit_id', 'oi_legacy_kit_id_idx');
            }
        });
    }

    public function down(): void
    {
        Schema::table('order_items', function (Blueprint $table) {
            if (Schema::hasColumn('order_items', 'legacy_kit_id')) {
                $table->dropIndex('oi_legacy_kit_id_idx');
                $table->dropColumn('legacy_kit_id');
            }
        });
    }
};
