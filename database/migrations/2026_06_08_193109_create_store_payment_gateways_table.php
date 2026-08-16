<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('store_payment_gateways', function (Blueprint $table) {
            $table->id();
            $table->foreignId('drop_store_id')->constrained('drop_stores')->cascadeOnDelete();
            $table->enum('gateway_type', ['pagarme', 'stripe', 'mercadopago', 'pix_manual']);
            $table->text('credentials_enc')->nullable();
            $table->string('pix_key', 255)->nullable();
            $table->string('pix_key_type', 20)->nullable();
            $table->boolean('is_default')->default(false);
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->unique(['drop_store_id', 'gateway_type']);
            $table->index('drop_store_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('store_payment_gateways');
    }
};
