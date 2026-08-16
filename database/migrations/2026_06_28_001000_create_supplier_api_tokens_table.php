<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('supplier_api_tokens', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('supplier_id')->index();
            $t->string('name', 100);
            $t->string('token_hash', 80)->unique();
            $t->string('prefix', 12)->index();
            $t->json('abilities')->nullable();
            $t->timestamp('last_used_at')->nullable();
            $t->timestamp('expires_at')->nullable();
            $t->boolean('active')->default(true);
            $t->unsignedBigInteger('created_by_user_id')->nullable();
            $t->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('supplier_api_tokens');
    }
};
