<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('supplier_billing_cycles', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('supplier_id')->index();
            $t->date('period_start');
            $t->date('period_end');
            $t->date('due_date');
            $t->unsignedInteger('clients_active')->default(0);
            $t->unsignedInteger('orders_count')->default(0);
            $t->decimal('amount_users', 12, 2)->default(0);
            $t->decimal('amount_orders', 12, 2)->default(0);
            $t->decimal('amount_extra', 12, 2)->default(0);
            $t->decimal('amount_total', 12, 2)->default(0);
            $t->enum('status', ['draft', 'open', 'paid', 'overdue', 'cancelled'])->default('open')->index();
            $t->string('payment_method', 30)->nullable();
            $t->string('payment_url', 500)->nullable();
            $t->string('pix_qr_code', 1000)->nullable();
            $t->timestamp('paid_at')->nullable();
            $t->string('external_invoice_id', 100)->nullable()->index();
            $t->text('notes')->nullable();
            $t->timestamps();
            $t->unique(['supplier_id', 'period_start', 'period_end'], 'sbc_supplier_period_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('supplier_billing_cycles');
    }
};
