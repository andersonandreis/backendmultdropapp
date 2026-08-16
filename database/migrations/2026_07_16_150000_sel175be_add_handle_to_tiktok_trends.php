<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * SEL-175BE Ruan 16/07 — Popular `handle` nos criadores.
 *
 * Contexto: tiktok_shop_trends.kind=creator ja tem `external_id` armazenando
 * o handle (@handle sem o @) pra rows criadas via TiktokTrendImportController
 * (SEL-122BE). Porem rows importadas pelo ingest legado (Playwright) usavam
 * `lead_id` como external_id — o handle real fica no raw JSON em
 * data-get(raw, 'handle') ou 'unique_id' ou 'tikwm_user.uniqueId'.
 *
 * Esta migration:
 *   1. Adiciona coluna `handle` VARCHAR(80) NULLABLE (indice pra lookup rapido).
 *   2. Backfilla `handle` a partir de:
 *      a) raw JSON `$.handle` se existir
 *      b) raw JSON `$.unique_id` se (a) faltar
 *      c) raw JSON `$.tikwm_user.uniqueId` (payload do SEL-122BE)
 *      d) `external_id` como ultimo fallback (assumindo que ja e o handle)
 *   3. Nao mexe em rows kind!=creator.
 *
 * Idempotente (migrations sao rodadas uma vez, mas os UPDATEs sao safe se
 * rodar de novo — deixa handle apontando pro valor mais atualizado do raw).
 */
return new class extends Migration {
    public function up(): void
    {
        if (!Schema::hasTable('tiktok_shop_trends')) {
            return; // nada a fazer
        }

        if (!Schema::hasColumn('tiktok_shop_trends', 'handle')) {
            Schema::table('tiktok_shop_trends', function (Blueprint $t) {
                $t->string('handle', 80)->nullable()->index()->after('external_id')
                    ->comment('SEL-175BE: handle @user do criador TikTok (kind=creator). Copia de external_id ou raw.handle.');
            });
        }

        // Backfill em cascata — cada UPDATE cobre um caso. UPDATEs sao IF-NULL
        // (nao sobrescrevem valor ja setado por comando manual anterior).

        // (a) raw.handle
        DB::statement("
            UPDATE tiktok_shop_trends
            SET handle = SUBSTRING(JSON_UNQUOTE(JSON_EXTRACT(raw, '$.handle')), 1, 80)
            WHERE kind = 'creator'
              AND handle IS NULL
              AND raw IS NOT NULL
              AND JSON_EXTRACT(raw, '$.handle') IS NOT NULL
              AND JSON_UNQUOTE(JSON_EXTRACT(raw, '$.handle')) != ''
        ");

        // (b) raw.unique_id
        DB::statement("
            UPDATE tiktok_shop_trends
            SET handle = SUBSTRING(JSON_UNQUOTE(JSON_EXTRACT(raw, '$.unique_id')), 1, 80)
            WHERE kind = 'creator'
              AND handle IS NULL
              AND raw IS NOT NULL
              AND JSON_EXTRACT(raw, '$.unique_id') IS NOT NULL
              AND JSON_UNQUOTE(JSON_EXTRACT(raw, '$.unique_id')) != ''
        ");

        // (c) raw.tikwm_user.uniqueId (SEL-122BE payload)
        DB::statement("
            UPDATE tiktok_shop_trends
            SET handle = SUBSTRING(JSON_UNQUOTE(JSON_EXTRACT(raw, '$.tikwm_user.uniqueId')), 1, 80)
            WHERE kind = 'creator'
              AND handle IS NULL
              AND raw IS NOT NULL
              AND JSON_EXTRACT(raw, '$.tikwm_user.uniqueId') IS NOT NULL
              AND JSON_UNQUOTE(JSON_EXTRACT(raw, '$.tikwm_user.uniqueId')) != ''
        ");

        // (d) fallback: external_id
        DB::statement("
            UPDATE tiktok_shop_trends
            SET handle = SUBSTRING(TRIM(LEADING '@' FROM external_id), 1, 80)
            WHERE kind = 'creator'
              AND handle IS NULL
              AND external_id IS NOT NULL
              AND external_id != ''
        ");
    }

    public function down(): void
    {
        if (Schema::hasTable('tiktok_shop_trends') && Schema::hasColumn('tiktok_shop_trends', 'handle')) {
            Schema::table('tiktok_shop_trends', function (Blueprint $t) {
                $t->dropIndex(['handle']);
                $t->dropColumn('handle');
            });
        }
    }
};
