<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('shipments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('producer_id')->constrained('suppliers');
            $table->foreignId('warehouse_id')->constrained('suppliers');
            $table->string('shipment_number');
            $table->string('status')->default('draft'); // draft, sent, in_transit, received, checking, checked
            $table->text('notes')->nullable();
            $table->integer('total_items')->default(0);
            $table->integer('total_checked')->default(0);
            $table->timestamp('sent_at')->nullable();
            $table->timestamp('received_at')->nullable();
            $table->timestamp('checked_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('shipments');
    }
};
