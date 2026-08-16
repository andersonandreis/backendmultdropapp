<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * NOV-118 — Coluna stock_alert_threshold em inventory.
 * Quando quantidade fica abaixo deste valor, dispara notificação Filament
 * (CheckLowStockJob diário + listener em tempo real).
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::table('inventory', function (Blueprint $table) {
            $table->integer('stock_alert_threshold')->nullable()->after('reserved');
        });
    }

    public function down(): void
    {
        Schema::table('inventory', function (Blueprint $table) {
            $table->dropColumn('stock_alert_threshold');
        });
    }
};
