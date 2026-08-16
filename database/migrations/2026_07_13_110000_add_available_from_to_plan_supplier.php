<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('plan_supplier') && ! Schema::hasColumn('plan_supplier', 'available_from')) {
            Schema::table('plan_supplier', function (Blueprint $table) {
                $table->date('available_from')->nullable()->after('supplier_id');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('plan_supplier') && Schema::hasColumn('plan_supplier', 'available_from')) {
            Schema::table('plan_supplier', function (Blueprint $table) {
                $table->dropColumn('available_from');
            });
        }
    }
};
