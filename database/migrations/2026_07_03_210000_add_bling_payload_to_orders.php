<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// MUL-139: pedido nativo (Shopee/ML) que tambem existe no Bling do seller ganha
// o espelho do JSON Bling (attach) ao inves de virar duplicata — integracao
// nativa tem prioridade, Bling complementa (NF-e, dados fiscais).
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->unsignedBigInteger('bling_order_id')->nullable()->after('capture_payload')->index();
            $table->json('bling_payload')->nullable()->after('bling_order_id');
        });
    }

    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->dropColumn(['bling_order_id', 'bling_payload']);
        });
    }
};
