<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// MUL-226-08: bloqueio manual de pedido pelo fornecedor (com auditoria em order_audit_log).
// Colunas nullable adicionadas ao fim da tabela (ALGORITHM=INSTANT no MySQL 8).
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            if (! Schema::hasColumn('orders', 'blocked_at')) {
                $table->timestamp('blocked_at')->nullable();
            }
            if (! Schema::hasColumn('orders', 'blocked_by')) {
                $table->unsignedBigInteger('blocked_by')->nullable();
            }
            if (! Schema::hasColumn('orders', 'block_reason')) {
                $table->string('block_reason', 500)->nullable();
            }
        });
    }

    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->dropColumn(['blocked_at', 'blocked_by', 'block_reason']);
        });
    }
};
