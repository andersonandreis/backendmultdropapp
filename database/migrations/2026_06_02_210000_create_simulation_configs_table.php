<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('simulation_configs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained('users')->onDelete('cascade');
            $table->string('slug')->unique();
            $table->decimal('revenue_per_month', 12, 2)->default(50000);
            $table->integer('orders_per_day')->default(30);
            $table->string('store_name')->default('Minha Loja');
            $table->string('store_link')->nullable();
            $table->boolean('label_enabled')->default(true);
            $table->json('product_links')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('simulation_configs');
    }
};
