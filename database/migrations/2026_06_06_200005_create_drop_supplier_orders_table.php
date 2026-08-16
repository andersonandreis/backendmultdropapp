<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('drop_supplier_orders', function (Blueprint $table) {
            $table->id();
            $table->foreignId('drop_order_id')->constrained('drop_orders')->onDelete('cascade');
            $table->string('supplier_slug')->nullable();
            $table->string('external_order_id')->nullable();
            $table->text('product_url')->nullable();
            $table->string('variant_title')->nullable();
            $table->decimal('cost_paid_usd', 10, 4)->default(0);
            $table->enum('status', ['pending', 'ordered', 'tracking_received', 'shipped', 'delivered', 'failed'])->default('pending');
            $table->string('tracking_code')->nullable();
            $table->string('tracking_carrier')->nullable();
            $table->json('purchase_evidence')->nullable();
            $table->text('notes')->nullable();
            $table->timestamp('ordered_at')->nullable();
            $table->timestamps();

            $table->index('drop_order_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('drop_supplier_orders');
    }
};
