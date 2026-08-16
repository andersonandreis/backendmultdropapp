<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        if (!Schema::hasColumn("plans", "affiliate_commission_percent")) {
            Schema::table("plans", function (Blueprint $table) {
                $table->decimal("affiliate_commission_percent", 5, 2)->default(0)->after("trial_days");
            });
        }
    }
    public function down(): void
    {
        Schema::table("plans", function (Blueprint $table) {
            $table->dropColumn("affiliate_commission_percent");
        });
    }
};
