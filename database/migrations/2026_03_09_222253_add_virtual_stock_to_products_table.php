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
        Schema::table('products', function (Blueprint $table) {
            $table->integer('virtual_stock_qty')->nullable()->after('is_active');
            $table->integer('safety_margin_stock')->default(20)->after('virtual_stock_qty');
            $table->integer('zero_out_margin_stock')->default(10)->after('safety_margin_stock');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->dropColumn([
                'virtual_stock_qty',
                'safety_margin_stock',
                'zero_out_margin_stock',
            ]);
        });
    }
};
