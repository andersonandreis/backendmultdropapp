<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

/**
 * SEL-425 — Pool de motores de vídeo (DICloak, Mac-Flow, etc).
 *
 * Cada linha = 1 slot de geração independente. O VideoEnginePool escolhe
 * em ordem de priority, pulando engines com cooldown ativo ou is_active=false.
 *
 * provider values:
 *   dicloak-flow  = DICloak VM (perfil Chrome com Google AI Pro)
 *   mac-flow      = Mac keepalive do Ruan (sessão local google-session.json)
 *   seedance      = API Seedance (futura)
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('video_engines', function (Blueprint $table) {
            $table->id();
            $table->string('name', 80);                                  // ex: "FlowTech01", "Mac-Ruan"
            $table->string('provider', 40)->default('mac-flow');         // dicloak-flow | mac-flow | seedance
            $table->json('config_json')->nullable();                     // profile_id, wsEndpoint, api_key, etc
            $table->unsignedSmallInteger('priority')->default(100);      // menor = tentado primeiro
            $table->boolean('is_active')->default(true);
            $table->timestamp('healthy_at')->nullable();                 // último sucesso
            $table->timestamp('last_failure_at')->nullable();
            $table->timestamp('cooldown_until')->nullable();             // skip até este momento
            $table->decimal('success_rate_24h', 5, 2)->nullable();       // 0-100
            $table->text('last_error')->nullable();
            $table->timestamps();
        });

        // Motor 1: DICloak (primário, priority=10)
        DB::table('video_engines')->insert([
            'name'      => 'DICloak-VEO3-01',
            'provider'  => 'dicloak-flow',
            'config_json' => json_encode([
                'profile_id' => null,   // será preenchido via admin ou .env DICLOAK_PROFILE_ID
                'tunnel_url' => null,   // preenchido via DICLOAK_TUNNEL_URL (INF-072)
            ]),
            'priority'  => 10,
            'is_active' => false,   // desativado até INF-072 entregar a URL do túnel
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        // Motor 2: Mac do Ruan (fallback, priority=99)
        DB::table('video_engines')->insert([
            'name'      => 'Mac-Ruan-Flow',
            'provider'  => 'mac-flow',
            'config_json' => json_encode([
                'session_path'   => '/home/api.seller.global/storage/kling-browser/google-session.json',
                'worker_js'      => '/home/api.seller.global/browser-worker/veo_generate.js',
                'worker_dir'     => '/home/api.seller.global/browser-worker',
                'project_url'    => null,   // lê VEO_PROJECT_URL do .env como fallback
            ]),
            'priority'  => 99,
            'is_active' => true,    // SEMPRE ligado como fallback silencioso
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public function down(): void
    {
        Schema::dropIfExists('video_engines');
    }
};
