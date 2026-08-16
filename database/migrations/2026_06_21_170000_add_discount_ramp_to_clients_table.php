<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('clients', function (Blueprint $table) {
            $table->unsignedInteger('discount_sales_count')->default(0)->after('is_active');
            $table->timestamp('discount_ramp_start')->nullable()->after('discount_sales_count');
            $table->unsignedTinyInteger('current_discount_percent')->default(50)->after('discount_ramp_start');
        });
    }

    public function down(): void
    {
        Schema::table('clients', function (Blueprint $table) {
            $table->dropColumn(['discount_sales_count', 'discount_ramp_start', 'current_discount_percent']);
        });
    }
};
