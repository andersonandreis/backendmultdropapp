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
            $table->foreignId('affiliate_id')->constrained('affiliates');
            $table->decimal('amount', 12, 2);
            $table->string('pix_key', 100);
            $table->enum('pix_type', ['cpf', 'cnpj', 'email', 'phone', 'random']);
            $table->enum('status', ['pending', 'processing', 'paid', 'rejected'])->default('pending');
            $table->timestamp('processed_at')->nullable();
            $table->text('rejection_reason')->nullable();
            $table->text('admin_notes')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('affiliate_withdrawals');
    }
};
