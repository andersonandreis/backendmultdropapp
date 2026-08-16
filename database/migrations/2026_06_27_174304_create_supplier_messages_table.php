<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * NOV-148 — Log de mensagens enviadas por supplier para clientes
 * (email/SMS/push/whatsapp). Usado pelo MensagensSellers no legado.
 *
 * Tabela 'email_logs' existente trata emails ja enviados pelo sistema (login,
 * notificacoes); esta tabela registra mensagens BROADCAST do supplier para
 * sua base de clientes (campanhas, avisos).
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::create('supplier_messages', function (Blueprint $table) {
            $table->id();
            $table->foreignId('supplier_id')->constrained('suppliers')->cascadeOnDelete();
            $table->enum('recipient_type', ['all', 'client', 'segment'])->default('all');
            $table->unsignedBigInteger('recipient_id')->nullable();
            $table->enum('channel', ['email', 'sms', 'push', 'whatsapp'])->default('email');
            $table->string('subject')->nullable();
            $table->longText('body');
            $table->timestamp('sent_at')->nullable();
            $table->enum('status', ['pending', 'sent', 'failed', 'partial'])->default('pending');
            $table->integer('recipients_count')->default(0);
            $table->integer('delivered_count')->default(0);
            $table->integer('failed_count')->default(0);
            $table->text('error_message')->nullable();
            $table->unsignedBigInteger('created_by_user_id')->nullable();
            $table->timestamps();

            $table->index(['supplier_id', 'status']);
            $table->index(['supplier_id', 'channel']);
            $table->index('sent_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('supplier_messages');
    }
};
