<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * MUL-227 item 31 Fase 4 — usuários secundários por client (seller).
 * O user "dono" da conta cadastra sub-users com permissões por menu.
 * `permissions` é JSON com { menu_key: bool } — vazio = todos os menus.
 */
return new class extends Migration {
    public function up(): void
    {
        if (! Schema::hasTable('sub_users')) {
            Schema::create('sub_users', function (Blueprint $t) {
                $t->id();
                $t->unsignedBigInteger('parent_user_id')->index();
                $t->string('name');
                $t->string('email')->unique();
                $t->string('password');
                $t->json('permissions')->nullable();
                $t->boolean('is_active')->default(true);
                $t->timestamp('last_login_at')->nullable();
                $t->unsignedBigInteger('created_by')->nullable();
                $t->timestamps();
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('sub_users');
    }
};
