<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('supplier_users', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('supplier_id')->index();
            $t->unsignedBigInteger('user_id')->index();
            $t->enum('role', ['admin', 'operador', 'estoque', 'financeiro', 'sac', 'logistica'])->default('operador');
            $t->json('permissions')->nullable();
            $t->boolean('active')->default(true);
            $t->string('invite_token', 64)->nullable()->index();
            $t->timestamp('invited_at')->nullable();
            $t->timestamp('accepted_at')->nullable();
            $t->unsignedBigInteger('invited_by_user_id')->nullable();
            $t->timestamps();
            $t->unique(['supplier_id', 'user_id'], 'su_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('supplier_users');
    }
};
