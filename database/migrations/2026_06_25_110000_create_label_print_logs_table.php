<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('label_print_logs', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('supplier_id')->index();
            $table->unsignedBigInteger('user_id')->nullable();
            $table->json('order_ids'); // array de IDs dos pedidos impressos
            $table->integer('batch_size')->default(1);
            $table->string('marketplace', 50)->nullable(); // shopee, mercadolivre, bling, mixed
            $table->string('printer_type', 20)->nullable(); // zebra, a4
            $table->timestamp('printed_at')->useCurrent();
            $table->timestamps();
            $table->index(['supplier_id', 'printed_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('label_print_logs');
    }
};
