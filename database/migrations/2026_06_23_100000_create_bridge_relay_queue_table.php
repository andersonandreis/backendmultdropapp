<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Fila persistente de retry para falhas no bridge legado (goolhub.io).
 *
 * Quando o relay ao legado falha (bridge offline, timeout, 5xx), o evento
 * e enfileirado aqui com delay exponencial. O RetryBridgeRelayJob processa
 * essa fila a cada 5 minutos.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('bridge_relay_queue', function (Blueprint $table) {
            $table->id();
            $table->string('platform', 30);
            $table->string('event_type', 60)->nullable();
            $table->unsignedBigInteger('order_id')->nullable();
            $table->integer('legacy_user_id')->nullable();
            $table->json('payload');
            $table->unsignedTinyInteger('attempts')->default(0);
            $table->timestamp('next_try_at')->useCurrent();
            $table->text('last_error')->nullable();
            $table->string('status', 20)->default('pending');
            $table->string('ml_order_id', 40)->nullable();
            $table->string('shopee_order_sn', 60)->nullable();
            $table->timestamps();

            $table->index(['status', 'next_try_at']);
            $table->index(['platform', 'status']);
            $table->index('order_id');
            $table->index('ml_order_id');
            $table->index('shopee_order_sn');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('bridge_relay_queue');
    }
};
