<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void {
        Schema::create('shopee_oauth_states', function (Blueprint $t) {
            $t->id();
            $t->string('state_token', 128)->unique();
            $t->string('service', 40);
            $t->string('user_id', 64);
            $t->string('return_url', 1024);
            $t->unsignedBigInteger('supplier_id')->nullable();
            $t->string('account_name', 120)->nullable();
            $t->json('extra')->nullable();
            $t->timestamp('expires_at')->index();
            $t->timestamp('consumed_at')->nullable();
            $t->string('ip', 45)->nullable();
            $t->string('user_agent', 512)->nullable();
            $t->timestamps();
            $t->index(['service', 'created_at']);
        });
    }
    public function down(): void { Schema::dropIfExists('shopee_oauth_states'); }
};
