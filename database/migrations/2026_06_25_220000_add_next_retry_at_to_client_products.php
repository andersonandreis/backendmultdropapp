<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * NOV-081: Adiciona next_retry_at em client_products para controle de backoff
 * no retry automático de sync_status=failed.
 * O campo sync_attempt_count já existe — reutilizado como retry_count.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('client_products', function (Blueprint $table) {
            if (! Schema::hasColumn('client_products', 'next_retry_at')) {
                $table->timestamp('next_retry_at')->nullable()->after('last_sync_at')
                    ->comment('NOV-081: Proxima tentativa permitida apos falha de sync');
            }
        });
    }

    public function down(): void
    {
        Schema::table('client_products', function (Blueprint $table) {
            $table->dropColumn('next_retry_at');
        });
    }
};
