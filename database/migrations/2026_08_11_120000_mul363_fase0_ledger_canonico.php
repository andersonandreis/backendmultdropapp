<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * MUL-363 Fase 0 — nucleo financeiro canonico.
 *
 * 1. client_supplier_transactions ganha os campos do LedgerEntryMeta:
 *    idempotency_key (UNIQUE — protecao dura contra dupla aplicacao),
 *    actor/origin (auditoria), reverses_transaction_id (contra-partida),
 *    meta JSON (extensao livre: payment_method, currency... sem migration futura).
 * 2. payment_events: log append-only de TODA tentativa (aplicada ou negada).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('client_supplier_transactions', function (Blueprint $table) {
            if (! Schema::hasColumn('client_supplier_transactions', 'idempotency_key')) {
                $table->string('idempotency_key', 120)->nullable()->unique('uq_cst_idempotency');
            }
            if (! Schema::hasColumn('client_supplier_transactions', 'actor')) {
                $table->string('actor', 80)->nullable();
            }
            if (! Schema::hasColumn('client_supplier_transactions', 'origin')) {
                $table->string('origin', 40)->nullable();
            }
            if (! Schema::hasColumn('client_supplier_transactions', 'reverses_transaction_id')) {
                $table->unsignedBigInteger('reverses_transaction_id')->nullable()->index('idx_cst_reverses');
            }
            if (! Schema::hasColumn('client_supplier_transactions', 'meta')) {
                $table->json('meta')->nullable();
            }
        });

        if (! Schema::hasTable('payment_events')) {
            Schema::create('payment_events', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('client_id');
                $table->unsignedBigInteger('supplier_id');
                $table->unsignedBigInteger('order_id')->nullable()->index();
                $table->string('event', 40)->index();
                $table->decimal('amount', 12, 2)->nullable();
                $table->decimal('balance_before', 12, 2)->nullable();
                $table->decimal('balance_after', 12, 2)->nullable();
                $table->unsignedBigInteger('transaction_id')->nullable();
                $table->string('actor', 80)->nullable();
                $table->string('origin', 40)->nullable();
                $table->json('context')->nullable();
                $table->timestamp('created_at')->useCurrent();
                $table->index(['client_id', 'supplier_id', 'created_at'], 'idx_pe_wallet_time');
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('payment_events');
        Schema::table('client_supplier_transactions', function (Blueprint $table) {
            foreach (['idempotency_key', 'actor', 'origin', 'reverses_transaction_id', 'meta'] as $col) {
                if (Schema::hasColumn('client_supplier_transactions', $col)) {
                    if ($col === 'idempotency_key') {
                        $table->dropUnique('uq_cst_idempotency');
                    }
                    if ($col === 'reverses_transaction_id') {
                        $table->dropIndex('idx_cst_reverses');
                    }
                    $table->dropColumn($col);
                }
            }
        });
    }
};
