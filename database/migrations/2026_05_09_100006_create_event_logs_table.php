<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('event_logs', function (Blueprint $table) {
            $table->id();

            $table->foreignId('event_subscription_id')
                ->constrained('event_subscriptions')
                ->cascadeOnDelete();

            $table->string('event_type', 50);
            $table->string('channel', 20); // 'email', 'in_app', 'push', 'webhook'

            $table->json('payload');

            $table->enum('status', ['pending', 'sent', 'failed'])->default('pending');
            $table->tinyInteger('attempts')->default(0);
            $table->timestamp('sent_at')->nullable();
            $table->text('error')->nullable();

            $table->timestamps();

            // Indexes para consultas frequentes
            $table->index('event_type');
            $table->index('status');
            $table->index('created_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('event_logs');
    }
};
