<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('auto_listing_queue_items', function (Blueprint $table) {
            $table->id();

            $table->foreignId('client_id')->constrained('clients')->cascadeOnDelete();
            $table->foreignId('marketplace_account_id')->constrained('marketplace_accounts')->cascadeOnDelete();
            $table->foreignId('product_id')->constrained('products')->cascadeOnDelete();

            $table->enum('status', ['pending', 'processing', 'completed', 'failed', 'skipped', 'cancelled'])->default('pending');

            // Conteúdo gerado pela IA
            $table->string('generated_title', 255)->nullable();
            $table->text('generated_description')->nullable();
            $table->json('generated_bullet_points')->nullable();

            // Resultado
            $table->foreignId('client_product_id')->nullable()->constrained('client_products')->nullOnDelete();
            $table->string('external_listing_id', 255)->nullable();
            $table->text('error_message')->nullable();
            $table->unsignedInteger('attempts')->default(0);
            $table->unsignedInteger('max_attempts')->default(3);

            $table->unsignedInteger('priority')->default(5);

            // Agendamento
            $table->timestamp('scheduled_at')->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('completed_at')->nullable();

            $table->timestamps();

            $table->unique(['marketplace_account_id', 'product_id'], 'unique_product_per_store');
            $table->index(['status', 'client_id', 'marketplace_account_id'], 'idx_pending_by_client');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('auto_listing_queue_items');
    }
};
