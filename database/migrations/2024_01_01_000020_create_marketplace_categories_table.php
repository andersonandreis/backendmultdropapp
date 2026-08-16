<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('marketplace_categories', function (Blueprint $table) {
            $table->id();
            $table->string('platform'); // mercadolivre, shopee
            $table->string('external_id');
            $table->string('name');
            $table->string('full_path');
            $table->string('parent_external_id')->nullable();
            $table->json('attributes_schema')->nullable();
            $table->timestamp('last_synced_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('marketplace_categories');
    }
};
