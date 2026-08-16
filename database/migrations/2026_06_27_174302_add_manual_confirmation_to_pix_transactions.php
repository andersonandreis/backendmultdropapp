<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * NOV-146 — Validar PIX manualmente.
 *
 * Permite que o supplier_admin confirme um PIX no painel quando o webhook
 * nao chegar (gateway com falha, modo manual etc).
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::table('pix_transactions', function (Blueprint $table) {
            $table->timestamp('manually_confirmed_at')->nullable()->after('paid_at');
            $table->unsignedBigInteger('confirmed_by_user_id')->nullable()->after('manually_confirmed_at');
            $table->text('manual_confirmation_note')->nullable()->after('confirmed_by_user_id');

            $table->index('manually_confirmed_at');
        });
    }

    public function down(): void
    {
        Schema::table('pix_transactions', function (Blueprint $table) {
            $table->dropIndex(['manually_confirmed_at']);
            $table->dropColumn(['manually_confirmed_at', 'confirmed_by_user_id', 'manual_confirmation_note']);
        });
    }
};
