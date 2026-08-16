<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('product_questions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('product_id')->constrained('products')->cascadeOnDelete();
            $table->foreignId('client_id')->constrained('clients')->cascadeOnDelete();
            $table->foreignId('supplier_id')->constrained('suppliers')->cascadeOnDelete();
            $table->text('question');
            $table->text('answer')->nullable();
            $table->unsignedBigInteger('answered_by_user_id')->nullable();
            $table->timestamp('answered_at')->nullable();
            $table->boolean('is_public')->default(true);
            $table->timestamps();

            $table->index(['product_id', 'is_public']);
            $table->index(['supplier_id', 'answered_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('product_questions');
    }
};
