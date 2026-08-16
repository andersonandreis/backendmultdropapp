<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('drop_attribution_sessions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('client_id')->constrained('clients')->onDelete('cascade');
            $table->string('session_id')->unique();
            $table->string('fbp')->nullable();
            $table->string('fbc')->nullable();
            $table->string('gclid')->nullable();
            $table->string('ttclid')->nullable();
            $table->string('utm_source')->nullable();
            $table->string('utm_campaign')->nullable();
            $table->string('utm_medium')->nullable();
            $table->string('utm_content')->nullable();
            $table->string('utm_term')->nullable();
            $table->text('landing_url')->nullable();
            $table->text('user_agent')->nullable();
            $table->string('ip_hash')->nullable();
            $table->timestamps();

            $table->index('client_id');
            $table->index('session_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('drop_attribution_sessions');
    }
};
