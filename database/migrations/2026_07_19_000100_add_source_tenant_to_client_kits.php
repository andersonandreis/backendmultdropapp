<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// MUL-236 F2 — origem do kit (slug do WL dono da tela), análogo a orders.tenant_slug.
// Hub usa pra saber pra qual WL empurrar o kit sincronizado. Nos WLs a coluna existe
// mas fica NULL (schema compartilhado nos 7 backends).
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasColumn('client_kits', 'source_tenant')) {
            return;
        }
        Schema::table('client_kits', function (Blueprint $table) {
            $table->string('source_tenant', 50)->nullable()->index()->after('legacy_kit_id');
        });
    }

    public function down(): void
    {
        if (! Schema::hasColumn('client_kits', 'source_tenant')) {
            return;
        }
        Schema::table('client_kits', function (Blueprint $table) {
            $table->dropIndex(['source_tenant']);
            $table->dropColumn('source_tenant');
        });
    }
};
