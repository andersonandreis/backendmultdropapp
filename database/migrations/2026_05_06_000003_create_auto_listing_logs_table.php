<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('auto_listing_logs', function (Blueprint $table) {
            $table->id();

            $table->foreignId('queue_item_id')->constrained('auto_listing_queue_items')->cascadeOnDelete();
            $table->foreignId('client_id')->constrained('clients')->cascadeOnDelete();

            $table->enum('action', ['ai_generate', 'create_draft', 'publish', 'retry', 'skip', 'fail', 'cancel']);
            $table->json('details')->nullable();
            $table->unsignedInteger('duration_ms')->nullable();

            $table->timestamp('created_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('auto_listing_logs');
    }
};
