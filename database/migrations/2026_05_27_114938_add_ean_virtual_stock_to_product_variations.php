<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table("product_variations", function (Blueprint $table) {
            if (!Schema::hasColumn("product_variations", "ean")) {
                $table->string("ean", 50)->nullable()->after("gtin");
            }
            if (!Schema::hasColumn("product_variations", "virtual_stock_qty")) {
                $table->integer("virtual_stock_qty")->default(0)->after("ean");
            }
        });
    }

    public function down(): void
    {
        Schema::table("product_variations", function (Blueprint $table) {
            $table->dropColumnIfExists("ean");
            $table->dropColumnIfExists("virtual_stock_qty");
        });
    }
};
