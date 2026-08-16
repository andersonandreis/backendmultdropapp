<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            if (!Schema::hasColumn('orders', 'label_status_reason')) {
                $table->string('label_status_reason', 500)->nullable()->after('label_url')
                    ->comment('Motivo padronizado da falha na busca de etiqueta (MES-046-A)');
            }
            if (!Schema::hasColumn('orders', 'label_error_at')) {
                $table->timestamp('label_error_at')->nullable()->after('label_status_reason')
                    ->comment('Ultima vez que o motivo de falha de etiqueta foi atualizado');
            }
        });
    }

    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->dropColumn(['label_status_reason', 'label_error_at']);
        });
    }
};
