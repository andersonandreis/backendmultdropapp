<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * SEL-GERENTE (09/08): hierarquia de gerente de afiliados.
 *
 * Um afiliado pode ser GERENTE ("guarda-chuva") e trazer outros afiliados
 * pra baixo dele (manager_id). O gerente ganha comissao override RECORRENTE
 * (manager_override_rate) sobre as vendas dos afiliados dele. Afiliados sob
 * um gerente NAO geram video sem autorizacao explicita (video_gen_authorized).
 *
 * NOTA: 'commission_rate' (override por afiliado) JA EXISTE na tabela desde
 * 2026_06_02_200001 (usado hoje em adminApprove/adminUpdateQuotas) — nao
 * duplicado aqui.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('affiliates', function (Blueprint $table) {
            if (! Schema::hasColumn('affiliates', 'is_manager')) {
                $table->boolean('is_manager')->default(false)->after('custom_referral_slug');
            }
            if (! Schema::hasColumn('affiliates', 'manager_id')) {
                $table->unsignedBigInteger('manager_id')->nullable()->after('is_manager');
            }
            if (! Schema::hasColumn('affiliates', 'video_gen_authorized')) {
                $table->boolean('video_gen_authorized')->default(true)->after('manager_id');
            }
            if (! Schema::hasColumn('affiliates', 'manager_override_rate')) {
                $table->decimal('manager_override_rate', 5, 2)->nullable()->after('video_gen_authorized');
            }
        });

        // FK + index em statement separado (evita erro se coluna ja existia sem FK)
        try {
            Schema::table('affiliates', function (Blueprint $table) {
                $table->foreign('manager_id')->references('id')->on('affiliates')->onDelete('set null');
            });
        } catch (\Throwable $e) {
            // FK ja existe ou driver nao suporta re-adicionar — segue o baile
        }

        try {
            Schema::table('affiliates', function (Blueprint $table) {
                $table->index(['manager_id'], 'affiliates_manager_id_idx');
            });
        } catch (\Throwable $e) {
        }
    }

    public function down(): void
    {
        Schema::table('affiliates', function (Blueprint $table) {
            try { $table->dropForeign(['manager_id']); } catch (\Throwable $e) {}
            try { $table->dropIndex('affiliates_manager_id_idx'); } catch (\Throwable $e) {}
        });
        Schema::table('affiliates', function (Blueprint $table) {
            $cols = ['is_manager', 'manager_id', 'video_gen_authorized', 'manager_override_rate'];
            foreach ($cols as $c) {
                if (Schema::hasColumn('affiliates', $c)) {
                    $table->dropColumn($c);
                }
            }
        });
    }
};
