<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('affiliate_withdrawals', function (Blueprint $table) {
            $table->id();
            $table->foreignId('affiliate_id')->constrained()->onDelete('cascade');
            $table->decimal('amount', 15, 2);
            $table->string('pix_key')->nullable();
            $table->enum('pix_type', ['cpf', 'cnpj', 'email', 'phone', 'random'])->nullable();
            $table->enum('status', ['pending', 'processing', 'paid', 'rejected'])->default('pending');
            $table->text('rejection_reason')->nullable();
            $table->timestamp('processed_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('affiliate_withdrawals');
    }
};
