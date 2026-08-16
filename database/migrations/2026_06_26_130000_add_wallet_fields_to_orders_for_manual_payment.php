<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * NOV-099 — garante presenca dos campos wallet_paid_at/wallet_transaction_id
 * em orders (idempotente). Esses campos sao usados pelo endpoint
 * POST /api/v1/orders/{id}/manual-payment para registrar pagamento via wallet
 * atomico (decrementa client_supplier_balances, cria ClientSupplierTransaction
 * tipo debit, Payment gateway=wallet status=paid).
 *
 * Os campos ja existem desde 2026_05_19 (add_auto_pay_wallet_fields). Esta
 * migracao apenas formaliza dependencia no historico de migracoes da feature.
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            if (! Schema::hasColumn('orders', 'wallet_paid_at')) {
                $table->timestamp('wallet_paid_at')->nullable()->after('paid_at')
                    ->comment('Data/hora do pagamento via wallet (manual-payment)');
            }
            if (! Schema::hasColumn('orders', 'wallet_transaction_id')) {
                $table->unsignedBigInteger('wallet_transaction_id')->nullable()->after('wallet_paid_at')
                    ->comment('ClientSupplierTransaction id do debit que pagou a order');
            }
        });
    }

    public function down(): void
    {
        // Nao remove — os campos sao compartilhados com auto-pay-wallet (2026_05_19).
    }
};
