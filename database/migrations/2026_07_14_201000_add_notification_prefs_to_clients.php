<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * MUL-227 item 28 — preferências de notificação por seller (client).
 * Estrutura JSON: {
 *   categories: { pedidos: bool, produtos: bool, sistema: bool, financeiro: bool },
 *   channels: { email: bool, push: bool },
 *   quiet_hours: { start: "22:00", end: "07:00" } | null,
 *   digest: "instant" | "hourly" | "daily",
 * }
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::table('clients', function (Blueprint $t) {
            if (! Schema::hasColumn('clients', 'notification_prefs')) {
                $t->json('notification_prefs')->nullable();
            }
        });
    }

    public function down(): void
    {
        Schema::table('clients', function (Blueprint $t) {
            if (Schema::hasColumn('clients', 'notification_prefs')) {
                $t->dropColumn('notification_prefs');
            }
        });
    }
};
