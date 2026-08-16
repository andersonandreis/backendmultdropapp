<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('supplier_banners', function (Blueprint $table) {
            $table->id();
            $table->foreignId('supplier_id')->constrained('suppliers')->cascadeOnDelete();
            $table->string('title');
            $table->string('url')->nullable();
            $table->string('image_url');
            $table->boolean('active')->default(true);
            $table->integer('sort_order')->default(0);
            $table->timestamps();

            $table->index(['supplier_id', 'active', 'sort_order']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('supplier_banners');
    }
};
