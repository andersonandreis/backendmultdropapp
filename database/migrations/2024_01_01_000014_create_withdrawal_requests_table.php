<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('withdrawal_requests', function (Blueprint $table) {
            $table->id();
            $table->foreignId('producer_id')->constrained('suppliers');
            $table->foreignId('warehouse_id')->constrained('suppliers');
            $table->decimal('amount', 10, 2);
            $table->string('status')->default('pending'); // pending, approved, paid, rejected
            $table->string('pix_key');
            $table->string('rejection_reason')->nullable();
            $table->timestamp('requested_at')->useCurrent();
            $table->timestamp('approved_at')->nullable();
            $table->timestamp('paid_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('withdrawal_requests');
    }
};
