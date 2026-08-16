<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Adiciona campos de metadados ao Tenant para suporte ao painel admin Filament.
 *
 * description - texto livre sobre o whitelabel
 * logo_url    - URL do logo do whitelabel
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::table('tenants', function (Blueprint $table) {
            $table->string('description', 1000)->nullable()->after('name');
            $table->string('logo_url', 500)->nullable()->after('description');
        });
    }

    public function down(): void
    {
        Schema::table('tenants', function (Blueprint $table) {
            $table->dropColumn(['description', 'logo_url']);
        });
    }
};
