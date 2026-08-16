<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('affiliates', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->onDelete('cascade');
            $table->string('referral_code', 20)->unique();
            $table->decimal('commission_rate', 5, 2)->default(10.00);
            $table->enum('status', ['active', 'inactive', 'suspended'])->default('active');
            $table->decimal('total_earned', 15, 2)->default(0);
            $table->decimal('total_withdrawn', 15, 2)->default(0);
            $table->string('pix_key')->nullable();
            $table->enum('pix_type', ['cpf', 'cnpj', 'email', 'phone', 'random'])->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('affiliates');
    }
};
