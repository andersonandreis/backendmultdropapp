<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
return new class extends Migration {
    public function up(): void {
        if (Schema::hasTable('suppliers') && !Schema::hasColumn('suppliers', 'hub_supplier_id')) {
            Schema::table('suppliers', function (Blueprint $t) {
                $t->unsignedBigInteger('hub_supplier_id')->nullable()->after('legacy_id')->index();
            });
        }
    }
    public function down(): void {
        Schema::table('suppliers', function (Blueprint $t) {
            if (Schema::hasColumn('suppliers','hub_supplier_id')) $t->dropColumn('hub_supplier_id');
        });
    }
};
