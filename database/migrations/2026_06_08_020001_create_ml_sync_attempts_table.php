<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ml_sync_attempts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('client_product_id')->constrained('client_products')->cascadeOnDelete();
            $table->timestamp('attempt_at')->useCurrent();
            $table->enum('status', ['success', 'error']);
            $table->text('error_message')->nullable();
            $table->timestamps();

            $table->index(['client_product_id', 'attempt_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ml_sync_attempts');
    }
};
