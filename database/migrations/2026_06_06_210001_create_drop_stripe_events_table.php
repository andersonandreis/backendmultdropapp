<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Migration: drop_stripe_events
 * Armazena todos os eventos Stripe recebidos via webhook para o modulo Drop Internacional.
 * Permite idempotencia (stripe_event_id unique) e rastreabilidade completa.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('drop_stripe_events', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('client_id');
            $table->string('stripe_event_id')->unique();
            $table->string('type');
            $table->unsignedBigInteger('drop_order_id')->nullable();
            $table->decimal('amount', 10, 4)->nullable();
            $table->string('currency', 3)->nullable();
            $table->string('status');
            $table->longText('payload');
            $table->timestamp('processed_at')->nullable();
            $table->timestamps();

            $table->foreign('client_id')->references('id')->on('clients')->onDelete('cascade');
            $table->foreign('drop_order_id')->references('id')->on('drop_orders')->onDelete('set null');
            $table->index(['client_id', 'type']);
            $table->index(['drop_order_id']);
            $table->index(['status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('drop_stripe_events');
    }
};
