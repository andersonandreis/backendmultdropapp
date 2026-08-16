<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * NOV-210: índices pras queries recorrentes do slow log (import legado +
 * AutoLinkMarketplaceProductsJob) que faziam full scan de products (82k) e
 * orders (77k) a cada execução.
 *
 * Defensiva (checa existência antes) porque roda nos 7 backends via sync.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasColumn('products', 'sku_normalized')) {
            DB::statement("ALTER TABLE products ADD COLUMN sku_normalized VARCHAR(255) GENERATED ALWAYS AS (UPPER(REPLACE(REPLACE(REPLACE(sku, '-', ''), '_', ''), ' ', ''))) VIRTUAL");
        }

        $this->addIndex('products', 'idx_products_sku_normalized', 'ADD INDEX idx_products_sku_normalized (sku_normalized)');
        $this->addIndex('products', 'idx_products_supplier_active_updated', 'ADD INDEX idx_products_supplier_active_updated (supplier_id, is_active, updated_at)');
        $this->addIndex('products', 'ft_products_name', 'ADD FULLTEXT ft_products_name (name)');
        $this->addIndex('orders', 'idx_orders_client_external', 'ADD INDEX idx_orders_client_external (client_id, external_order_id)');
        $this->addIndex('orders', 'idx_orders_client_ordernum', 'ADD INDEX idx_orders_client_ordernum (client_id, order_number)');
    }

    public function down(): void
    {
        $this->dropIndex('orders', 'idx_orders_client_ordernum');
        $this->dropIndex('orders', 'idx_orders_client_external');
        $this->dropIndex('products', 'ft_products_name');
        $this->dropIndex('products', 'idx_products_supplier_active_updated');
        $this->dropIndex('products', 'idx_products_sku_normalized');

        if (Schema::hasColumn('products', 'sku_normalized')) {
            DB::statement('ALTER TABLE products DROP COLUMN sku_normalized');
        }
    }

    private function addIndex(string $table, string $index, string $ddl): void
    {
        if (empty(DB::select("SHOW INDEX FROM `$table` WHERE Key_name = ?", [$index]))) {
            DB::statement("ALTER TABLE `$table` $ddl");
        }
    }

    private function dropIndex(string $table, string $index): void
    {
        if (!empty(DB::select("SHOW INDEX FROM `$table` WHERE Key_name = ?", [$index]))) {
            DB::statement("ALTER TABLE `$table` DROP INDEX `$index`");
        }
    }
};
