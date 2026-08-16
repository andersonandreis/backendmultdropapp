<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('forbidden_words', function (Blueprint $table) {
            $table->id();
            $table->string('word')->unique();
            $table->string('context')->nullable()->comment('Ex: shopee, mercadolivre, ou nulo para global');
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('forbidden_words');
    }
};
