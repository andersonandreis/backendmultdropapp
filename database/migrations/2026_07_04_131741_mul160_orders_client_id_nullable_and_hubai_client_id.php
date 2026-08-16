<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

/**
 * MUL-160 - orders vindas do hub via webhook mesmo sem client mapeado localmente.
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::table("orders", function (Blueprint $t) {
            $t->unsignedBigInteger("client_id")->nullable()->change();

            if (!Schema::hasColumn("orders", "hubai_client_id")) {
                $t->unsignedBigInteger("hubai_client_id")->nullable()->after("hubai_order_id")
                    ->comment("MUL-160: id do client no HUB");
            }
        });

        try {
            DB::statement("ALTER TABLE orders ADD INDEX orders_hubai_client_id_idx (hubai_client_id)");
        } catch (\Throwable $e) {}
    }

    public function down(): void
    {
        Schema::table("orders", function (Blueprint $t) {
            if (Schema::hasColumn("orders", "hubai_client_id")) {
                $t->dropColumn("hubai_client_id");
            }
        });
    }
};
