<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * NOV-207 Etapa 3: coluna admin_note pra auto-nota do recebimento externo
 * (confirmado pelo fornecedor fora do sistema, com observacoes + auditoria).
 *
 * Historico anexado em texto (append), preserva confirmar+estornos anteriores.
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::table('orders', function (Blueprint $t) {
            $t->text('admin_note')->nullable()->after('seller_notes');
        });
    }

    public function down(): void
    {
        Schema::table('orders', function (Blueprint $t) {
            $t->dropColumn('admin_note');
        });
    }
};
