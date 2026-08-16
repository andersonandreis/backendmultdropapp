<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            if (! Schema::hasColumn('orders', 'nfe_entrada_status')) {
                $table->enum('nfe_entrada_status', ['pending', 'received', 'rejected', 'exempt', 'cancelled'])
                      ->default('pending')->nullable()->after('invoice_xml')->index();
            }
            if (! Schema::hasColumn('orders', 'nfe_entrada_received_at')) {
                $table->timestamp('nfe_entrada_received_at')->nullable()->after('nfe_entrada_status');
            }
            if (! Schema::hasColumn('orders', 'nfe_entrada_updated_at')) {
                $table->timestamp('nfe_entrada_updated_at')->nullable()->after('nfe_entrada_received_at');
            }
        });
    }

    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->dropColumn(['nfe_entrada_status', 'nfe_entrada_received_at', 'nfe_entrada_updated_at']);
        });
    }
};
