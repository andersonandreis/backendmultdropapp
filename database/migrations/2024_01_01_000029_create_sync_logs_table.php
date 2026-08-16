<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('sync_logs', function (Blueprint $table) {
            $table->id();
            $table->string('syncable_type')->nullable(); // product, order, inventory
            $table->unsignedBigInteger('syncable_id')->nullable();
            $table->string('platform'); // mercadolivre, shopee, bling
            $table->unsignedBigInteger('marketplace_account_id')->nullable();
            $table->string('action'); // create, update, delete, sync, webhook
            $table->string('direction'); // outbound, inbound
            $table->string('status'); // success, error, pending
            $table->json('request_payload')->nullable();
            $table->json('response_payload')->nullable();
            $table->text('error_message')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sync_logs');
    }
};
