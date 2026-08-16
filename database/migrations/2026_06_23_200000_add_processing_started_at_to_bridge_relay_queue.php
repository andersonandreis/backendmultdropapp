<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * NOV-046 P0 #2 — Recovery de bridge_relay_queue stuck em 'processing'
 *
 * Quando o worker do RetryBridgeRelayJob marca um item como 'processing' e
 * crasha antes de finalizar (timeout, OOM, deploy), o item fica orfao para
 * sempre porque o job so le WHERE status = 'pending'.
 *
 * Solucao: timestamp 'processing_started_at' permite detectar stuck > 10min
 * e devolve-los para 'pending' no inicio de cada execucao.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('bridge_relay_queue', function (Blueprint $table) {
            $table->timestamp('processing_started_at')->nullable()->after('status');
            $table->index(['status', 'processing_started_at'], 'idx_status_processing_started_at');
        });
    }

    public function down(): void
    {
        Schema::table('bridge_relay_queue', function (Blueprint $table) {
            $table->dropIndex('idx_status_processing_started_at');
            $table->dropColumn('processing_started_at');
        });
    }
};
