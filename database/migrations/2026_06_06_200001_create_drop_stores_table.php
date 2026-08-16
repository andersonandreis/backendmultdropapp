<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('drop_stores', function (Blueprint $table) {
            $table->id();
            $table->foreignId('client_id')->constrained('clients')->onDelete('cascade');
            $table->string('shop_domain')->unique();
            $table->text('access_token')->nullable();
            $table->string('shopify_shop_id')->nullable();
            $table->enum('status', ['pending', 'active', 'inactive', 'uninstalled'])->default('pending');
            $table->string('shop_name')->nullable();
            $table->string('currency', 3)->nullable();
            $table->string('plan_name')->nullable();
            $table->timestamp('webhook_registered_at')->nullable();
            $table->timestamp('last_sync_at')->nullable();
            $table->timestamps();

            $table->index('client_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('drop_stores');
    }
};
