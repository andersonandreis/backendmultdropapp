<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table("marketplace_accounts", function (Blueprint $t) {
            if (!Schema::hasColumn("marketplace_accounts", "identification_type")) {
                $t->string("identification_type", 20)->nullable()->after("ml_user_id");
            }
            if (!Schema::hasColumn("marketplace_accounts", "identification_number")) {
                $t->string("identification_number", 20)->nullable()->after("identification_type");
            }
        });
    }
    public function down(): void
    {
        Schema::table("marketplace_accounts", function (Blueprint $t) {
            $t->dropColumn(["identification_type", "identification_number"]);
        });
    }
};
