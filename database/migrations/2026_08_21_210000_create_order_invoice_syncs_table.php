<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * MUL-454: controle da cadeia automatica de NF do seller (tentativas limitadas + alerta).
 * Uma linha por pedido observado; attempts so cresce em FALHA (espera transitoria nao).
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('order_invoice_syncs')) {
            return;
        }

        Schema::create('order_invoice_syncs', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('order_id')->unique();
            $table->unsignedTinyInteger('attempts')->default(0);
            $table->string('status', 30)->default('pending'); // pending | resolved | failed
            $table->string('reason', 500)->nullable();
            $table->unsignedBigInteger('bling_pedido_id')->nullable();
            $table->unsignedBigInteger('bling_nfe_id')->nullable();
            $table->unsignedTinyInteger('nfe_situacao')->nullable();
            $table->timestamp('last_checked_at')->nullable();
            $table->timestamp('alerted_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('order_invoice_syncs');
    }
};
