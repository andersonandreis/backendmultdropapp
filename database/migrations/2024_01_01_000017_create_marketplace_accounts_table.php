<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('marketplace_accounts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('client_id')->constrained()->cascadeOnDelete();
            $table->string('platform'); // mercadolivre, shopee

            $table->string('app_id')->nullable();
            $table->text('access_token')->nullable();
            $table->text('refresh_token')->nullable();

            $table->timestamp('token_expires_at')->nullable();
            $table->timestamp('refresh_token_expires_at')->nullable();

            $table->string('seller_id')->nullable();
            $table->string('seller_nickname')->nullable();
            $table->string('shop_id')->nullable();

            $table->string('status')->default('active'); // active, disconnected, expired, pending
            $table->timestamp('last_sync_at')->nullable();
            $table->timestamp('last_token_refresh_at')->nullable();
            $table->integer('sync_errors_count')->default(0);

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('marketplace_accounts');
    }
};
