<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * SEL-227 Ruan 18/07/2026 — anti-fraude / anti-clone.
 *
 * Registra device fingerprint (browser hash equivalente ao MAC) + IP no
 * cadastro/login. Sem validacao email/telefone, essa e a unica defesa
 * contra abuso "grátis pra sempre" — mesmo device NAO pode criar N contas
 * gratuitas.
 *
 * Composto por: canvas hash + WebGL hash + hardware profile + user agent.
 * Nao coleta MAC real (browser nao expoe por privacy sandbox), mas o
 * fingerprint composto e ~99% unico por maquina.
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::create('device_fingerprints', function (Blueprint $t) {
            $t->id();
            $t->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $t->string('ip', 45)->index();                    // IPv4/IPv6
            $t->string('ip_forwarded', 255)->nullable();      // X-Forwarded-For chain
            $t->string('fingerprint_hash', 64)->index();      // hash composto (SHA-256)
            $t->string('canvas_hash', 64)->nullable();
            $t->string('webgl_hash', 64)->nullable();
            $t->string('screen_hash', 32)->nullable();
            $t->text('user_agent')->nullable();
            $t->string('platform', 64)->nullable();
            $t->string('language', 16)->nullable();
            $t->string('timezone', 64)->nullable();
            $t->tinyInteger('hardware_concurrency')->nullable();
            $t->tinyInteger('device_memory')->nullable();
            $t->boolean('is_headless')->default(false);       // navigator.webdriver / 0 plugins
            $t->boolean('is_datacenter_ip')->default(false);  // AWS/GCP/Azure ranges
            $t->string('event', 32);                           // register|login|heartbeat
            $t->timestamp('first_seen_at')->useCurrent();
            $t->timestamp('last_seen_at')->useCurrent();
            $t->timestamps();

            $t->index(['fingerprint_hash', 'created_at']);
            $t->index(['ip', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('device_fingerprints');
    }
};
