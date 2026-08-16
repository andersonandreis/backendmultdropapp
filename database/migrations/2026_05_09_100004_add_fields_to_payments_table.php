<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('payments', function (Blueprint $table) {
            // Supplier que processou o pagamento (null = plataforma)
            $table->foreignId('supplier_id')
                ->nullable()
                ->after('client_id')
                ->constrained('suppliers')
                ->nullOnDelete();

            // Divisao de valor entre wallet e PIX
            $table->decimal('wallet_amount', 10, 2)->default(0)->after('amount');
            $table->decimal('pix_amount', 10, 2)->default(0)->after('wallet_amount');
            $table->decimal('fee_amount', 10, 2)->default(0)->after('pix_amount');

            // Referencia a transacao PIX
            $table->foreignId('pix_transaction_id')
                ->nullable()
                ->after('fee_amount')
                ->constrained('pix_transactions')
                ->nullOnDelete();

            // Informacoes de estorno
            $table->timestamp('refunded_at')->nullable()->after('paid_at');
            $table->decimal('refund_amount', 10, 2)->nullable()->after('refunded_at');
            $table->string('refund_reason')->nullable()->after('refund_amount');
        });
    }

    public function down(): void
    {
        Schema::table('payments', function (Blueprint $table) {
            $table->dropForeign(['supplier_id']);
            $table->dropForeign(['pix_transaction_id']);
            $table->dropColumn([
                'supplier_id',
                'wallet_amount',
                'pix_amount',
                'fee_amount',
                'pix_transaction_id',
                'refunded_at',
                'refund_amount',
                'refund_reason',
            ]);
        });
    }
};
