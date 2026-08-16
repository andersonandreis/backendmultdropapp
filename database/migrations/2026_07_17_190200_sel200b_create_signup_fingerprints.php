<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * SEL-200B: fingerprint antifraude no signup.
 * Coleta IP, ASN, browser_fp, push_endpoint, user_agent, timezone
 * pra bloquear multiplas contas do mesmo dispositivo em 30d.
 */
return new class extends Migration {
    public function up(): void
    {
        if (Schema::hasTable('signup_fingerprints')) return;
        Schema::create('signup_fingerprints', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('user_id')->nullable()->index();
            $t->string('ip_address', 64)->index();
            $t->string('ip_country', 4)->nullable();
            $t->string('ip_asn', 32)->nullable();
            $t->string('browser_fp', 64)->nullable()->index();
            $t->string('push_endpoint_hash', 64)->nullable()->index();
            $t->text('user_agent')->nullable();
            $t->string('accept_language', 32)->nullable();
            $t->string('timezone', 64)->nullable();
            $t->json('flags')->nullable();
            $t->timestamps();

            $t->index(['ip_address', 'created_at']);
            $t->index(['browser_fp', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('signup_fingerprints');
    }
};
