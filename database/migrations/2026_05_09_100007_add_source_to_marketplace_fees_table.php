<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Adiciona coluna `source` a marketplace_fees.
 *
 * Indica a origem da taxa:
 *   api     — sincronizada via API do marketplace (ex: ML listing types)
 *   manual  — editada manualmente pelo admin no Filament
 *   default — inserida pelo seed de defaults (fees:sync --seed)
 *
 * Nota: listing_type_id ja existe desde a migration original
 * (2024_01_01_000019_create_marketplace_fees_table.php).
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::table('marketplace_fees', function (Blueprint $table) {
            $table->string('source')->default('manual')->after('is_active')
                ->comment('api | manual | default');
        });
    }

    public function down(): void
    {
        Schema::table('marketplace_fees', function (Blueprint $table) {
            $table->dropColumn('source');
        });
    }
};
