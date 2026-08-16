<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void {
        Schema::table("shopee_oauth_states", function (Blueprint $t) {
            $t->string("source_system", 50)->nullable()->after("account_name");
        });
    }

    public function down(): void {
        Schema::table("shopee_oauth_states", function (Blueprint $t) {
            $t->dropColumn("source_system");
        });
    }
};
