<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('marketplace_accounts', function (Blueprint $table) {
            $table->string('import_mode')->default('manual')->after('status')->comment('manual, automatic');
            $table->string('pricing_strategy')->default('fixed')->after('import_mode')->comment('fixed, margin_percentage, margin_fixed');
            $table->decimal('price_margin', 10, 2)->default(0)->after('pricing_strategy');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('marketplace_accounts', function (Blueprint $table) {
            $table->dropColumn(['import_mode', 'pricing_strategy', 'price_margin']);
        });
    }
};
