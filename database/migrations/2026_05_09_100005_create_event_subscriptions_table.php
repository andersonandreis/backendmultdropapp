<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('event_subscriptions', function (Blueprint $table) {
            $table->id();

            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();

            // null = super admin global (nao vinculado a supplier especifico)
            $table->foreignId('supplier_id')
                ->nullable()
                ->constrained('suppliers')
                ->nullOnDelete();

            // Ex: 'order.paid', 'payment.confirmed', '*' para todos os eventos
            $table->string('event_type', 50);

            // Ex: ["email","in_app","webhook"]
            $table->json('channels');

            // Webhook de destino (opcional)
            $table->string('webhook_url')->nullable();
            $table->string('webhook_secret', 64)->nullable();

            $table->boolean('is_active')->default(true);
            $table->timestamps();

            // Combinacao unica: um usuario nao pode ter duas subscriptions
            // para o mesmo evento no mesmo supplier
            $table->unique(['user_id', 'supplier_id', 'event_type']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('event_subscriptions');
    }
};
