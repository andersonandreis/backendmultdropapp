<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * NOV-108: Adiciona coluna de idempotencia para baixa de estoque.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->timestamp('stock_decremented_at')->nullable()->after('wallet_transaction_id')
                ->comment('NOV-108: timestamp da baixa de estoque (idempotencia)');
        });
    }

    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->dropColumn('stock_decremented_at');
        });
    }
};
