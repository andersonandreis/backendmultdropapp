<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('tutorials', function (Blueprint $table) {
            $table->id();
            $table->string('title');
            $table->text('description')->nullable();
            $table->string('video_url')->nullable();
            $table->string('target_page')->nullable(); // Ex: 'orders', 'invoices' - onde o botão de ajuda contextual aparece
            $table->unsignedBigInteger('required_plan_id')->nullable(); // Restrição por nivel de assinatura
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->foreign('required_plan_id')->references('id')->on('plans')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tutorials');
    }
};
