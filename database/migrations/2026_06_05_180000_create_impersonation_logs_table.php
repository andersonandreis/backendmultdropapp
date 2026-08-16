<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('impersonation_logs', function (Blueprint $table) {
            $table->id();
            $table->uuid('token')->unique();
            $table->unsignedBigInteger('admin_id');
            $table->unsignedBigInteger('target_user_id');
            $table->string('ip_address', 45)->nullable();
            $table->timestamp('used_at')->nullable();
            $table->timestamp('expires_at');
            $table->timestamp('created_at')->useCurrent();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('impersonation_logs');
    }
};
