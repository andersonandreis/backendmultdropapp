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
        Schema::table('suppliers', function (Blueprint $table) {
            $table->string('prefix', 10)->nullable()->after('type'); // Ex: SUP, MGA, etc.
        });

        Schema::table('orders', function (Blueprint $table) {
            $table->string('packing_photo_url')->nullable()->after('total');
            $table->string('return_code')->nullable()->after('cancel_reason');
            $table->string('return_status')->nullable()->after('return_code'); // requested, received, refunded
            $table->string('invoice_url')->nullable()->after('invoice_issued_at');
            $table->string('invoice_xml_url')->nullable()->after('invoice_url');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->dropColumn([
                'packing_photo_url',
                'return_code',
                'return_status',
                'invoice_url',
                'invoice_xml_url'
            ]);
        });

        Schema::table('suppliers', function (Blueprint $table) {
            $table->dropColumn('prefix');
        });
    }
};
