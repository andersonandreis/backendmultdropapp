<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * SEL-357: adiciona colunas mirror_source_* em orders e client_products
     * para rastrear origem dos dados espelhados e permitir idempotencia no sync.
     */
    public function up(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->string('mirror_source_backend', 50)
                  ->nullable()
                  ->after('origin_tenant_slug')
                  ->comment('SEL-357: backend de origem se order eh espelho (ex: multdrop)');
            $table->unsignedBigInteger('mirror_source_order_id')
                  ->nullable()
                  ->after('mirror_source_backend')
                  ->comment('SEL-357: id do pedido no backend de origem');
            $table->unsignedBigInteger('mirror_source_client_id')
                  ->nullable()
                  ->after('mirror_source_order_id')
                  ->comment('SEL-357: client_id de origem no backend espelhado');
        });

        Schema::table('client_products', function (Blueprint $table) {
            $table->string('mirror_source_backend', 50)
                  ->nullable()
                  ->comment('SEL-357: backend de origem se produto eh espelho');
            $table->unsignedBigInteger('mirror_source_product_id')
                  ->nullable()
                  ->comment('SEL-357: id do produto no backend de origem');
        });
    }

    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->dropColumn(['mirror_source_backend', 'mirror_source_order_id', 'mirror_source_client_id']);
        });

        Schema::table('client_products', function (Blueprint $table) {
            $table->dropColumn(['mirror_source_backend', 'mirror_source_product_id']);
        });
    }
};
