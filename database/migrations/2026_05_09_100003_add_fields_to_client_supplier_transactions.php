<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('client_supplier_transactions', function (Blueprint $table) {
            // Referencia externa ao gateway (external_id da pix_transaction)
            $table->string('reference')->nullable()->after('description');

            // Saldo da wallet apos esta transacao
            $table->decimal('running_balance', 12, 2)->nullable()->after('reference');

            // Tipo semantico da transacao
            // Valores: 'pix_topup', 'order_debit', 'refund', 'manual_credit', 'adjustment'
            $table->string('transaction_type', 30)->nullable()->after('running_balance');

            // Relacionamento com a transacao PIX que originou este registro
            $table->foreignId('pix_transaction_id')
                ->nullable()
                ->after('transaction_type')
                ->constrained('pix_transactions')
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('client_supplier_transactions', function (Blueprint $table) {
            $table->dropForeign(['pix_transaction_id']);
            $table->dropColumn(['reference', 'running_balance', 'transaction_type', 'pix_transaction_id']);
        });
    }
};
