<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * MUL-142-F — Upload de imagem em chamados + avaliacao de atendimento.
 * Idempotente: verifica coluna antes de adicionar.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('support_tickets', function (Blueprint $table) {
            if (!Schema::hasColumn('support_tickets', 'rating')) {
                $table->unsignedTinyInteger('rating')->nullable()->after('closed_at');
            }
            if (!Schema::hasColumn('support_tickets', 'rating_comment')) {
                $table->string('rating_comment', 500)->nullable()->after('rating');
            }
            if (!Schema::hasColumn('support_tickets', 'rated_at')) {
                $table->timestamp('rated_at')->nullable()->after('rating_comment');
            }
        });
    }

    public function down(): void
    {
        Schema::table('support_tickets', function (Blueprint $table) {
            $table->dropColumn(['rating', 'rating_comment', 'rated_at']);
        });
    }
};
