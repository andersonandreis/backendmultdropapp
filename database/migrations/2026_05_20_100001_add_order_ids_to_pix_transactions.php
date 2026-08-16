<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('pix_transactions', function (Blueprint $table) {
            // IDs dos pedidos vinculados (para pagamento parcial ou puro via PIX)
            $table->json('order_ids')->nullable()->after('order_id')
                ->comment('IDs dos pedidos a serem pagos por este PIX (pagamento parcial/puro)');

            // Valor debitado do saldo antes de gerar PIX (pagamento parcial)
            $table->decimal('balance_used', 10, 2)->default(0)->after('net_amount')
                ->comment('Saldo da carteira usado antes de gerar PIX (pagamento parcial)');
        });
    }

    public function down(): void
    {
        Schema::table('pix_transactions', function (Blueprint $table) {
            $table->dropColumn(['order_ids', 'balance_used']);
        });
    }
};
