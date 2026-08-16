<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * MUL-226-03 — Central de Notificações do painel do fornecedor.
 * Regras persistidas por categoria/evento com janela de dias+horário e canal.
 * Um Job/Command futuro consulta NotificationRule::isAllowedNow($cat,$event)
 * antes de enviar — assim a UI aqui já vale mesmo sem worker novo agora.
 */
return new class extends Migration {
    public function up(): void
    {
        if (! Schema::hasTable('notification_rules')) {
            Schema::create('notification_rules', function (Blueprint $t) {
                $t->id();
                $t->unsignedBigInteger('supplier_id')->nullable()->index();
                $t->string('category', 32);
                $t->string('event', 64);
                $t->json('days_of_week')->nullable();
                $t->time('time_start')->default('09:00:00');
                $t->time('time_end')->default('18:00:00');
                $t->string('channel', 16)->default('email');
                $t->boolean('enabled')->default(true);
                $t->json('extra')->nullable();
                $t->unsignedBigInteger('created_by')->nullable();
                $t->timestamps();

                $t->unique(['supplier_id', 'category', 'event'], 'notif_rules_scope_unique');
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('notification_rules');
    }
};
