<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// SEL-171: campos no pedido pra rastrear bonus/desconto do catalogo no
// momento da criacao. Ao pagar o pedido (payOrder / confirmOrderPix),
// se pending_subsidy_amount > 0 a gente cria row em catalog_bonus_subsidies
// e incrementa clients.first_orders_used.
return new class extends Migration {
    public function up(): void
    {
        if (Schema::hasTable('orders')) {
            Schema::table('orders', function (Blueprint $t) {
                if (!Schema::hasColumn('orders', 'catalog_bonus_applied')) {
                    $t->boolean('catalog_bonus_applied')->default(false)->after('block_reason');
                }
                if (!Schema::hasColumn('orders', 'catalog_original_price')) {
                    $t->decimal('catalog_original_price', 12, 2)->nullable()->after('catalog_bonus_applied');
                }
                if (!Schema::hasColumn('orders', 'catalog_discounted_price')) {
                    $t->decimal('catalog_discounted_price', 12, 2)->nullable()->after('catalog_original_price');
                }
                if (!Schema::hasColumn('orders', 'pending_subsidy_amount')) {
                    $t->decimal('pending_subsidy_amount', 12, 2)->nullable()->after('catalog_discounted_price');
                }
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('orders')) {
            Schema::table('orders', function (Blueprint $t) {
                foreach (['pending_subsidy_amount', 'catalog_discounted_price', 'catalog_original_price', 'catalog_bonus_applied'] as $col) {
                    if (Schema::hasColumn('orders', $col)) {
                        $t->dropColumn($col);
                    }
                }
            });
        }
    }
};
