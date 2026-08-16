<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('order_beeps', function (Blueprint $table) {
            $table->id();
            $table->foreignId('order_id')->constrained('orders')->onDelete('cascade');
            $table->foreignId('beeped_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('beeped_at')->nullable();
            $table->timestamps();

            $table->unique('order_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('order_beeps');
    }
};
