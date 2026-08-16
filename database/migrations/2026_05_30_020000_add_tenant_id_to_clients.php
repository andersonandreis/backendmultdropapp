<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Supplier Core / Fase 3 / P2 — clients.tenant_id (nullable).
 *
 * Cada client agora carrega o tenant a que pertence. OrderObserver::creating()
 * usa essa coluna pra setar order.tenant_id antes do INSERT (em vez de cair no
 * default hubai_uuid).
 *
 * Backfill: mapping do legado MySQL tudoonline_production.login.id_empresa
 * (descoberto em 29/05 — ver memoria 2026-05-29).
 *
 *   client 2,10,11,12 -> id_empresa=24 -> multdrop
 *   client 3          -> id_empresa=1  -> hubai
 *   demais (5,6,7,8)  -> NULL (sem orders, deixa o observer cair em hubai)
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::table('clients', function (Blueprint $table) {
            $table->uuid('tenant_id')->nullable()->after('id');
            $table->foreign('tenant_id')->references('id')->on('tenants');
            $table->index('tenant_id');
        });

        $multdropUuid = DB::table('tenants')->where('slug', 'multdrop')->value('id');
        $hubaiUuid    = DB::table('tenants')->where('slug', 'hubai')->value('id');

        DB::table('clients')->whereIn('id', [2, 10, 11, 12])->update(['tenant_id' => $multdropUuid]);
        DB::table('clients')->where('id', 3)->update(['tenant_id' => $hubaiUuid]);
    }

    public function down(): void
    {
        Schema::table('clients', function (Blueprint $table) {
            $table->dropForeign(['tenant_id']);
            $table->dropIndex(['tenant_id']);
            $table->dropColumn('tenant_id');
        });
    }
};
