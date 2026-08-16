<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('order_status_history')) {
            return;
        }

        Schema::create('order_status_history', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('order_id')->index();
            $table->string('field', 64)->default('order_processing_status')->comment('Campo que mudou: order_processing_status ou status');
            $table->string('from_status', 64)->nullable();
            $table->string('to_status', 64)->nullable();
            $table->string('actor_type', 32)->default('system')->comment('bip|painel|webhook|sync|system');
            $table->string('actor_id', 64)->nullable()->comment('user_id ou identificador do ator');
            $table->string('origin', 32)->default('bip')->comment('bip|api|webhook|legacy_sync|observer');
            $table->json('metadata')->nullable();
            $table->timestamp('created_at')->useCurrent();

            $table->foreign('order_id')->references('id')->on('orders')->onDelete('cascade');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('order_status_history');
    }
};
