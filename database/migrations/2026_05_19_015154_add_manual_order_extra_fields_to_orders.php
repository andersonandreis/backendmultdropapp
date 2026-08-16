<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->string('channel_name', 100)->nullable()->after('manual_reason')
                  ->comment('Canal de origem do pedido manual (Instagram, WhatsApp, Site...)');
            $table->string('delivery_type', 50)->nullable()->after('channel_name')
                  ->comment('Tipo de entrega: correios, transportadora, retirada, motoboy, sedex, pac');
            $table->string('notes', 500)->nullable()->after('delivery_type')
                  ->comment('Observacoes internas do pedido manual');
        });
    }

    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->dropColumn(['channel_name', 'delivery_type', 'notes']);
        });
    }
};
