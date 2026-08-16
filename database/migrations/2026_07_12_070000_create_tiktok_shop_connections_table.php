<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * SEL-046: OAuth TikTok Shop Partner API — 1 conexao por (tenant,user,shop).
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::create('tiktok_shop_connections', function (Blueprint $t) {
            $t->bigIncrements('id');
            $t->unsignedBigInteger('tenant_id')->nullable()->index();
            $t->unsignedBigInteger('user_id')->index();
            $t->string('shop_id', 64)->index();
            $t->string('shop_name', 200)->nullable();
            $t->string('shop_region', 8)->default('BR');
            $t->string('seller_id', 64)->nullable();
            $t->string('open_id', 64)->nullable();
            $t->text('access_token');
            $t->text('refresh_token')->nullable();
            $t->unsignedBigInteger('access_token_expire_at')->nullable();  // epoch seconds
            $t->unsignedBigInteger('refresh_token_expire_at')->nullable();
            $t->string('grant_type', 24)->default('authorization_code');
            $t->string('scopes', 500)->nullable();
            $t->string('status', 16)->default('active'); // active|revoked|expired
            $t->timestamps();
            $t->unique(['user_id','shop_id']);
        });
    }
    public function down(): void { Schema::dropIfExists('tiktok_shop_connections'); }
};
