<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * MUL-147: Adiciona legacy_kit_id em client_kits para rastrear origem do kit importado
 * do legado. Permite idempotência na conversão bundles→client_kits.
 * Adiciona também client_kit_id e is_kit_component em order_items para
 * a explosão de pedidos de kit em componentes.
 */
return new class extends Migration
{
    public function up(): void
    {
        // 1. legacy_kit_id em client_kits
        Schema::table('client_kits', function (Blueprint $table) {
            if (! Schema::hasColumn('client_kits', 'legacy_kit_id')) {
                $table->unsignedBigInteger('legacy_kit_id')->nullable()->after('is_active');
                $table->index('legacy_kit_id', 'ck_legacy_kit_id_idx');
            }
        });

        // 2. Colunas para explosão de kit em order_items
        Schema::table('order_items', function (Blueprint $table) {
            if (! Schema::hasColumn('order_items', 'client_kit_id')) {
                $table->unsignedBigInteger('client_kit_id')->nullable()->after('legacy_kit_id');
                $table->index('client_kit_id', 'oi_client_kit_id_idx');
            }
            if (! Schema::hasColumn('order_items', 'is_kit_component')) {
                $table->boolean('is_kit_component')->default(false)->after('client_kit_id');
            }
            // kit_item_original_id: ID do order_item "pai" (o kit antes de explodir)
            if (! Schema::hasColumn('order_items', 'kit_source_item_id')) {
                $table->unsignedBigInteger('kit_source_item_id')->nullable()->after('is_kit_component');
                $table->index('kit_source_item_id', 'oi_kit_source_item_idx');
            }
        });
    }

    public function down(): void
    {
        Schema::table('client_kits', function (Blueprint $table) {
            if (Schema::hasColumn('client_kits', 'legacy_kit_id')) {
                $table->dropIndex('ck_legacy_kit_id_idx');
                $table->dropColumn('legacy_kit_id');
            }
        });

        Schema::table('order_items', function (Blueprint $table) {
            foreach ([
                'oi_client_kit_id_idx'   => 'client_kit_id',
                'oi_kit_source_item_idx' => 'kit_source_item_id',
            ] as $idx => $col) {
                if (Schema::hasColumn('order_items', $col)) {
                    $table->dropIndex($idx);
                    $table->dropColumn($col);
                }
            }
            if (Schema::hasColumn('order_items', 'is_kit_component')) {
                $table->dropColumn('is_kit_component');
            }
        });
    }
};
