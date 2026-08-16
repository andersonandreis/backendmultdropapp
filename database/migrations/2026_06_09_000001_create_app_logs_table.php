<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('app_logs', function (Blueprint $table) {
            $table->id();
            $table->string('tenant_id', 36)->nullable()->index();
            $table->unsignedBigInteger('user_id')->nullable()->index();
            $table->enum('level', ['error', 'warning', 'info', 'debug'])->index();
            $table->string('channel', 60)->index();
            $table->string('event', 120)->index();
            $table->text('message');
            $table->json('context')->nullable();
            $table->string('ip', 45)->nullable();
            $table->string('request_id', 36)->nullable();
            $table->unsignedInteger('duration_ms')->nullable();
            $table->timestamp('created_at')->useCurrent()->index();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('app_logs');
    }
};
