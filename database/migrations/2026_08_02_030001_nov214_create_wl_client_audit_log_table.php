<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('wl_client_audit_log', function (Blueprint $table) {
            $table->id();
            $table->unsignedTinyInteger('empresa_id')->index();
            $table->string('wl_database', 60);
            $table->unsignedBigInteger('client_id');
            $table->string('email', 191)->nullable();
            $table->enum('action', ['deactivate', 'delete', 'reactivate'])->index();
            $table->json('before')->nullable();
            $table->json('after')->nullable();
            $table->timestamp('changed_at')->useCurrent()->index();
            $table->unsignedBigInteger('changed_by_user_id')->nullable();
            $table->string('ip_address', 45)->nullable();
            $table->timestamps();

            $table->index(['empresa_id', 'client_id']);
            $table->index(['empresa_id', 'changed_at']);
            $table->index(['email', 'changed_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('wl_client_audit_log');
    }
};
