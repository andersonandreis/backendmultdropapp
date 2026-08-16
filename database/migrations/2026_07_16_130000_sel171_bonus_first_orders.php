<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// SEL-171: sistema real do bonus 50% nas 3 primeiras vendas do catalogo.
// Antes ficava no localStorage do cliente (fragil). Agora rastreamos no banco
// e cada pedido pago com bonus gera row em catalog_bonus_subsidies com a
// diferenca (original - discounted) que o Ruan paga por fora pro fornecedor.
return new class extends Migration {
    public function up(): void
    {
        if (Schema::hasTable('clients')) {
            Schema::table('clients', function (Blueprint $t) {
                if (!Schema::hasColumn('clients', 'first_orders_used')) {
                    $t->unsignedTinyInteger('first_orders_used')->default(0)->after('discount_ramp_start');
                }
                if (!Schema::hasColumn('clients', 'first_orders_bonus_pct')) {
                    $t->unsignedTinyInteger('first_orders_bonus_pct')->default(50)->after('first_orders_used');
                }
            });
        }

        if (!Schema::hasTable('catalog_bonus_subsidies')) {
            Schema::create('catalog_bonus_subsidies', function (Blueprint $t) {
                $t->id();
                $t->foreignId('client_id')->index();
                $t->foreignId('supplier_id')->index();
                $t->unsignedBigInteger('order_id')->index();
                $t->string('product_name', 255)->nullable();
                $t->unsignedInteger('product_id')->nullable()->index();
                $t->decimal('original_price', 12, 2);
                $t->decimal('discounted_price', 12, 2);
                $t->decimal('subsidy_amount', 12, 2)->index(); // = original - discounted
                $t->enum('status', ['pending', 'paid', 'waived'])->default('pending')->index();
                $t->timestamp('paid_at')->nullable();
                $t->foreignId('paid_by_admin_id')->nullable();
                $t->text('admin_note')->nullable();
                $t->timestamps();
                $t->unique(['order_id']); // um subsidio por pedido
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('catalog_bonus_subsidies');
        if (Schema::hasTable('clients')) {
            Schema::table('clients', function (Blueprint $t) {
                if (Schema::hasColumn('clients', 'first_orders_bonus_pct')) {
                    $t->dropColumn('first_orders_bonus_pct');
                }
                if (Schema::hasColumn('clients', 'first_orders_used')) {
                    $t->dropColumn('first_orders_used');
                }
            });
        }
    }
};
