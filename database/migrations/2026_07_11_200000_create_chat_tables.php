<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('chat_conversations', function (Blueprint $table) {
            $table->id();
            $table->string('uuid', 36)->unique()->comment('UUID público exposto ao frontend');
            $table->unsignedBigInteger('client_id')->nullable()->comment('NULL = visitante deslogado');
            $table->string('visitor_name', 120)->nullable();
            $table->string('visitor_email', 191)->nullable();
            $table->enum('mode', ['sales', 'support'])->default('sales')->comment('sales=deslogado, support=logado');
            $table->enum('status', ['open', 'resolved', 'handoff'])->default('open');
            $table->unsignedBigInteger('chatwoot_conversation_id')->nullable()->comment('ID da conversa espelhada no Chatwoot');
            $table->string('chatwoot_contact_id', 64)->nullable();
            $table->timestamp('handoff_at')->nullable();
            $table->timestamps();

            $table->index('client_id');
            $table->index('status');
            $table->index('chatwoot_conversation_id');
        });

        Schema::create('chat_messages', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('conversation_id');
            $table->enum('sender', ['user', 'agent'])->default('user');
            $table->text('content');
            $table->boolean('from_chatwoot')->default(false)->comment('TRUE = veio de resposta do agente no Chatwoot');
            $table->unsignedBigInteger('chatwoot_message_id')->nullable();
            $table->timestamps();

            $table->foreign('conversation_id')->references('id')->on('chat_conversations')->onDelete('cascade');
            $table->index(['conversation_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('chat_messages');
        Schema::dropIfExists('chat_conversations');
    }
};
