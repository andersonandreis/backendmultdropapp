<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * NOV-148 — supplier_messages ganha payload/response para metadata do envio.
 *
 * - payload: dados enviados para o gateway (request body)
 * - response: resposta do gateway (debug + auditoria)
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::table('supplier_messages', function (Blueprint $table) {
            if (!Schema::hasColumn('supplier_messages', 'payload')) {
                $table->longText('payload')->nullable()->after('error_message');
            }
            if (!Schema::hasColumn('supplier_messages', 'response')) {
                $table->longText('response')->nullable()->after('payload');
            }
        });
    }

    public function down(): void
    {
        Schema::table('supplier_messages', function (Blueprint $table) {
            if (Schema::hasColumn('supplier_messages', 'payload')) {
                $table->dropColumn('payload');
            }
            if (Schema::hasColumn('supplier_messages', 'response')) {
                $table->dropColumn('response');
            }
        });
    }
};
