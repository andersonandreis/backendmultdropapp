<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * SEL-137 Ruan 00:45: admin quer ligar/desligar push por plano.
 * push_admin_enabled: quando cliente desse plano gera venda, admin recebe push?
 * push_client_enabled: quando cliente desse plano loga/atualiza, ele recebe push?
 * Default: true/true pra nao quebrar comportamento atual.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('plans', function (Blueprint $t) {
            if (!Schema::hasColumn('plans', 'push_admin_enabled')) {
                $t->boolean('push_admin_enabled')->default(true)->after('is_active');
            }
            if (!Schema::hasColumn('plans', 'push_client_enabled')) {
                $t->boolean('push_client_enabled')->default(true)->after('push_admin_enabled');
            }
        });
    }

    public function down(): void
    {
        Schema::table('plans', function (Blueprint $t) {
            if (Schema::hasColumn('plans', 'push_client_enabled')) $t->dropColumn('push_client_enabled');
            if (Schema::hasColumn('plans', 'push_admin_enabled')) $t->dropColumn('push_admin_enabled');
        });
    }
};
