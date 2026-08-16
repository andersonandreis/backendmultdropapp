<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('shopee_oauth_callbacks', function (Blueprint $table) {
            $table->id();
            $table->string('shop_id')->nullable()->index();
            $table->text('code')->nullable();
            $table->timestamp('received_at')->useCurrent();
            $table->boolean('processed')->default(false);
            $table->string('source_ip', 45)->nullable();
            $table->json('raw_params')->nullable()->comment('Todos os params recebidos da Shopee');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('shopee_oauth_callbacks');
    }
};
