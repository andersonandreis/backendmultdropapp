<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Campo pra rastrear origem do legado nos produtos
        Schema::table('products', function (Blueprint $table) {
            $table->unsignedInteger('legacy_sku_pai_id')->nullable()->after('id')->index();
        });

        // Campo pra rastrear origem do legado nos fornecedores
        Schema::table('suppliers', function (Blueprint $table) {
            $table->unsignedInteger('legacy_id')->nullable()->after('id')->index();
        });
    }

    public function down(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->dropColumn('legacy_sku_pai_id');
        });

        Schema::table('suppliers', function (Blueprint $table) {
            $table->dropColumn('legacy_id');
        });
    }
};
