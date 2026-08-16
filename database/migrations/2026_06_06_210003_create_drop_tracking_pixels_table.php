<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('drop_tracking_pixels', function (Blueprint $table) {
            $table->id();
            $table->foreignId('client_id')->constrained('clients')->onDelete('cascade');
            $table->enum('platform', ['meta', 'google', 'tiktok']);
            $table->string('pixel_id');
            $table->text('access_token')->nullable();
            $table->string('test_event_code')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->unique(['client_id', 'platform', 'pixel_id']);
            $table->index('client_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('drop_tracking_pixels');
    }
};
