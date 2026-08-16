<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('auto_listing_configs', function (Blueprint $table) {
            $table->id();

            $table->foreignId('client_id')->nullable()->constrained('clients')->cascadeOnDelete();
            $table->foreignId('marketplace_account_id')->nullable()->constrained('marketplace_accounts')->cascadeOnDelete();

            // Velocidade
            $table->unsignedInteger('max_listings_per_hour')->default(20);
            $table->unsignedInteger('max_listings_per_day')->default(200);
            $table->unsignedInteger('delay_between_listings_seconds')->default(30);

            // Horário ativo
            $table->time('active_hours_start')->default('08:00');
            $table->time('active_hours_end')->default('22:00');
            $table->json('active_days')->default('["mon","tue","wed","thu","fri","sat"]');

            // IA
            $table->boolean('ai_enabled')->default(true);
            $table->boolean('ai_generate_title')->default(true);
            $table->boolean('ai_generate_description')->default(true);
            $table->text('ai_instructions')->nullable();
            $table->string('ai_model', 50)->default('gpt-4o-mini');

            // Comportamento
            $table->boolean('auto_publish')->default(false);
            $table->boolean('skip_existing')->default(true);
            $table->boolean('overwrite_custom_fields')->default(false);

            // Status e permissões
            $table->enum('status', ['active', 'paused', 'disabled'])->default('active');
            $table->boolean('seller_can_customize')->default(false);
            $table->unsignedInteger('priority')->default(5);

            $table->timestamps();

            $table->unique(['client_id', 'marketplace_account_id'], 'unique_config_per_client_account');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('auto_listing_configs');
    }
};
