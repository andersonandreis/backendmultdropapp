<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// SEL-050 — Web Push (Seller Global)
return new class extends Migration {
    public function up(): void
    {
        if (Schema::hasTable('push_subscriptions')) {
            return;
        }
        Schema::create('push_subscriptions', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('client_id')->nullable()->index();
            $table->unsignedBigInteger('user_id')->nullable()->index();
            $table->text('endpoint');
            $table->string('endpoint_hash', 64)->unique();
            $table->string('p256dh', 255);
            $table->string('auth', 255);
            $table->string('user_agent', 255)->nullable();
            $table->timestamp('last_used_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('push_subscriptions');
    }
};
