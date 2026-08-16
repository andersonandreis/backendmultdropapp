<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * SEL-GERENTE (09/08): convites de gerente de afiliados.
 * Gerente gera um token; quem se cadastra/aceita por ele entra com
 * manager_id = gerente + video_gen_authorized = false.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('affiliate_manager_invites')) {
            return;
        }

        Schema::create('affiliate_manager_invites', function (Blueprint $table) {
            $table->id();
            $table->foreignId('manager_affiliate_id')->constrained('affiliates')->onDelete('cascade');
            $table->string('token', 64)->unique();
            $table->unsignedBigInteger('created_by_user_id')->nullable();
            $table->unsignedBigInteger('used_by_affiliate_id')->nullable();
            $table->timestamp('used_at')->nullable();
            $table->timestamp('expires_at')->nullable();
            $table->timestamps();

            $table->index(['manager_affiliate_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('affiliate_manager_invites');
    }
};
