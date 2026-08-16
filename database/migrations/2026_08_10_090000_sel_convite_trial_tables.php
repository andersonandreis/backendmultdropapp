<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

/**
 * SEL-CONVITE Fase A — trial fechado por link /convite.
 *
 * trial_invites : fonte da verdade do trial (1 por email, relogio 24h do
 *                 servidor, o 1 video, fingerprint+ip anti-abuso).
 * convite_waitlist : lista de espera (mode=waitlist OU teto diario batido).
 * settings(group=convite): toggle mode, teto diario, limiar auto-pausa, oferta.
 *
 * Tudo aditivo — nao altera nada existente. Reversivel no down().
 */
return new class extends Migration {
    public function up(): void
    {
        if (! Schema::hasTable('trial_invites')) {
            Schema::create('trial_invites', function (Blueprint $t) {
                $t->id();
                $t->unsignedBigInteger('user_id')->nullable()->index();
                $t->string('email')->index();
                $t->string('fingerprint_hash', 64)->nullable()->index();
                $t->string('signup_ip', 45)->nullable()->index();
                $t->timestamp('started_at')->nullable();
                $t->timestamp('expires_at')->nullable()->index();
                $t->timestamp('video_used_at')->nullable();
                $t->unsignedBigInteger('video_pipeline_id')->nullable();
                $t->enum('status', ['active', 'expired', 'consumed'])->default('active')->index();
                $t->string('consumed_reason', 64)->nullable();
                $t->timestamps();
                $t->index(['email', 'status']);
            });
        }

        if (! Schema::hasTable('convite_waitlist')) {
            Schema::create('convite_waitlist', function (Blueprint $t) {
                $t->id();
                $t->string('email')->unique();
                $t->string('fingerprint_hash', 64)->nullable()->index();
                $t->string('ip', 45)->nullable()->index();
                $t->enum('status', ['waiting', 'notified', 'converted', 'blocked'])->default('waiting')->index();
                $t->timestamp('notified_at')->nullable();
                $t->string('batch_id', 40)->nullable()->index();
                $t->timestamps();
            });
        }

        $now  = now();
        $seed = [
            ['group' => 'convite', 'key' => 'mode',                'value' => 'waitlist'],
            ['group' => 'convite', 'key' => 'daily_cap',           'value' => '50'],
            ['group' => 'convite', 'key' => 'autopause_threshold', 'value' => '200'],
            ['group' => 'convite', 'key' => 'offer_json',          'value' => json_encode(['label' => 'Plano Ultra', 'price' => 'R$297', 'url' => '/planos'])],
        ];
        foreach ($seed as $row) {
            $exists = DB::table('settings')->where('group', $row['group'])->where('key', $row['key'])->exists();
            if (! $exists) {
                DB::table('settings')->insert(array_merge($row, ['created_at' => $now, 'updated_at' => $now]));
            }
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('trial_invites');
        Schema::dropIfExists('convite_waitlist');
        DB::table('settings')->where('group', 'convite')->delete();
    }
};
