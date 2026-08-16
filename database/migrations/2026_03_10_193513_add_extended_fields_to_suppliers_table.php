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
        Schema::table('suppliers', function (Blueprint $table) {
            // Financial
            $table->boolean('allows_direct_payment')->default(false)->after('is_active');
            $table->decimal('pix_fee', 5, 2)->default(0)->after('allows_direct_payment');

            // Operational
            $table->boolean('is_factory')->default(false)->after('pix_fee');
            $table->boolean('supports_meli_flex')->default(false)->after('is_factory');
            $table->decimal('flex_fee', 5, 2)->default(0)->after('supports_meli_flex');
            $table->boolean('allows_direct_deposit')->default(true)->after('flex_fee');

            // Branding/UI
            $table->string('display_name')->nullable()->after('company_name');
            $table->string('theme_color')->nullable()->after('display_name');

            // Private Hub Support
            if (!Schema::hasColumn('suppliers', 'owner_client_id')) {
                $table->string('owner_client_id')->nullable()->after('theme_color');
            }
            if (!Schema::hasColumn('suppliers', 'is_private')) {
                $table->boolean('is_private')->default(false)->after('owner_client_id');
            }
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('suppliers', function (Blueprint $table) {
            $table->dropColumn([
                'allows_direct_payment',
                'pix_fee',
                'is_factory',
                'supports_meli_flex',
                'flex_fee',
                'allows_direct_deposit',
                'display_name',
                'theme_color',
                'owner_client_id',
                'is_private',
            ]);
        });
    }
};
