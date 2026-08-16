<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('drop_tracking_updates', function (Blueprint $table) {
            $table->id();
            $table->foreignId('drop_supplier_order_id')->constrained('drop_supplier_orders')->onDelete('cascade');
            $table->string('status');
            $table->string('location')->nullable();
            $table->text('description')->nullable();
            $table->timestamp('occurred_at')->nullable();
            $table->enum('source', ['webhook', 'polling', 'manual'])->default('manual');
            $table->json('raw_payload')->nullable();
            $table->timestamps();

            $table->index('drop_supplier_order_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('drop_tracking_updates');
    }
};
