<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table("orders", function (Blueprint $table) {
            $table->timestamp("marketplace_created_at")->nullable()->after("cancelled_at");
            $table->index("marketplace_created_at", "orders_marketplace_created_at_idx");
        });
    }

    public function down(): void
    {
        Schema::table("orders", function (Blueprint $table) {
            $table->dropIndex("orders_marketplace_created_at_idx");
            $table->dropColumn("marketplace_created_at");
        });
    }
};
