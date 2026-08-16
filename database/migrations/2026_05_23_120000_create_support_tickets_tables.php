<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Backend de chamados (Fase 5) — substitui o localStorage atual do
 * /chamados (`Tickets.tsx`). Cliente nao perde tickets ao trocar de
 * aparelho ou limpar cache.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('support_tickets', function (Blueprint $table) {
            $table->id();
            $table->foreignId('client_id')->constrained('clients')->cascadeOnDelete();
            $table->string('title', 200);
            $table->string('category', 50)->default('other'); // payment, order, product, integration, other
            $table->string('priority', 20)->default('medium'); // low, medium, high, urgent
            $table->string('status', 20)->default('new');      // new, in_progress, resolved, closed
            $table->text('description')->nullable();
            $table->unsignedBigInteger('related_order_id')->nullable();
            $table->timestamp('resolved_at')->nullable();
            $table->timestamp('closed_at')->nullable();
            $table->unsignedBigInteger('closed_by_user_id')->nullable();
            $table->timestamps();

            $table->index(['client_id', 'status']);
            $table->index('created_at');
        });

        Schema::create('support_ticket_messages', function (Blueprint $table) {
            $table->id();
            $table->foreignId('ticket_id')->constrained('support_tickets')->cascadeOnDelete();
            $table->string('author_type', 20); // client, admin, system
            $table->unsignedBigInteger('author_user_id')->nullable();
            $table->text('body');
            $table->json('attachments')->nullable();
            $table->timestamps();

            $table->index(['ticket_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('support_ticket_messages');
        Schema::dropIfExists('support_tickets');
    }
};
