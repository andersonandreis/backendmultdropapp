<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('slugs', function (Blueprint $table) {
            $table->id();
            $table->string('sluggable_type');
            $table->unsignedBigInteger('sluggable_id');
            $table->string('slug')->unique();
            $table->boolean('is_canonical')->default(true);
            $table->timestamps();

            $table->index(['sluggable_type', 'sluggable_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('slugs');
    }
};
