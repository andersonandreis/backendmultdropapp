<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Supplier Core / Fase 3 / P3 — rate limit configuravel por tenant.
 * Default 100 req/min (mesmo do hardcoded anterior).
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::table('tenants', function (Blueprint $table) {
            $table->unsignedInteger('rate_limit_per_min')->default(100)->after('write_enabled');
        });
    }

    public function down(): void
    {
        Schema::table('tenants', function (Blueprint $table) {
            $table->dropColumn('rate_limit_per_min');
        });
    }
};
