<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('webhook_logs', function (Blueprint $table) {
            $table->id();

            // De qual marketplace veio a notificação
            $table->string('platform', 50)->index();

            // Tópico do evento (orders, items, shipments, questions, etc.)
            $table->string('topic', 100)->nullable();

            // Resource afetado (ex: "/orders/2000003114851541")
            $table->string('resource', 255)->nullable();

            // User/seller ID no marketplace
            $table->string('user_id', 100)->nullable()->index();

            // Status do processamento interno
            $table->enum('status', ['received', 'processed', 'failed'])->default('received')->index();

            // Payload completo da requisição (para replay/debug)
            $table->json('payload')->nullable();

            // Mensagem de erro, se houver
            $table->text('error_message')->nullable();

            // Quando o job foi despachado/processado com sucesso
            $table->timestamp('processed_at')->nullable();

            $table->timestamp('created_at')->useCurrent();

            // Índice composto para consultas de auditoria
            $table->index(['platform', 'status', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('webhook_logs');
    }
};
