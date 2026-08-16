<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('drop_module_configs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('client_id')->constrained('clients')->onDelete('cascade');
            $table->boolean('is_active')->default(false);
            $table->string('stripe_account_id')->nullable();
            $table->string('stripe_account_status')->nullable();
            $table->string('target_country', 2)->default('US');
            $table->string('currency', 3)->default('USD');
            $table->enum('fulfillment_mode', ['manual', 'assisted', 'auto'])->default('manual');
            $table->decimal('default_markup_pct', 5, 2)->default(40.00);
            $table->decimal('platform_fee_pct', 5, 2)->default(5.00);
            $table->decimal('gateway_fee_pct', 5, 2)->default(3.50);
            $table->timestamps();

            $table->index('client_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('drop_module_configs');
    }
};
