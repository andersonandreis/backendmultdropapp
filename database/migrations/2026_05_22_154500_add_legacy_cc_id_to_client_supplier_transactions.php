<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('client_supplier_transactions', function (Blueprint $table) {
            if (!Schema::hasColumn('client_supplier_transactions', 'legacy_cc_id')) {
                $table->unsignedBigInteger('legacy_cc_id')->nullable()->after('order_id');
                $table->index(['client_id', 'legacy_cc_id'], 'cst_client_legacy_cc_idx');
            }
        });
    }

    public function down(): void
    {
        Schema::table('client_supplier_transactions', function (Blueprint $table) {
            $table->dropIndex('cst_client_legacy_cc_idx');
            $table->dropColumn('legacy_cc_id');
        });
    }
};
