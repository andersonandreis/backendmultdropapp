<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('order_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('order_id')->constrained()->cascadeOnDelete();
            $table->string('event_type'); // recebido, pagamento_confirmado, nf_validada, embalado, enviado, entregue, cancelado, devolucao
            $table->string('description')->nullable();
            $table->json('metadata')->nullable(); // For saving related info such as photo_url or nf_key
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete(); // Who triggered it
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('order_events');
    }
};
