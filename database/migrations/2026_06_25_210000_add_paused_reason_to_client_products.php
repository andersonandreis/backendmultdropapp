<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('client_products', function (Blueprint $table) {
            // NOV-081: rastreia motivo de pausa para diferenciar pausa automatica (stock_zero)
            // de pausa manual ou por review — evita reativar anuncio manualmente pausado
            // valores: null (sem pausa), 'stock_zero', 'manual', 'review', 'needs_reauth'
            $table->string('paused_reason')->nullable()->after('listing_status');
        });
    }

    public function down(): void
    {
        Schema::table('client_products', function (Blueprint $table) {
            $table->dropColumn('paused_reason');
        });
    }
};
