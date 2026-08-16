<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

/**
 * SEL-429 - Motor Universal DICloak: renomeia video_engines -> ai_engines
 * e adiciona coluna tool_type para distinguir por tipo de ferramenta.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::rename('video_engines', 'ai_engines');

        Schema::table('ai_engines', function (Blueprint $table) {
            $table->enum('tool_type', ['video', 'image', 'llm', 'scraping', 'viral', 'flow', 'other'])
                  ->default('video')
                  ->after('provider');
        });

        DB::table('ai_engines')->update(['tool_type' => 'video']);

        if (!DB::table('ai_engines')->where('provider', 'openai-direct')->exists()) {
            DB::table('ai_engines')->insert([
                'name'        => 'OpenAI Direto',
                'provider'    => 'openai-direct',
                'tool_type'   => 'llm',
                'config_json' => json_encode(['note' => 'Fallback LLM direto OpenAI. Usa OPENAI_API_KEY do .env.']),
                'priority'    => 99,
                'is_active'   => true,
                'created_at'  => now(),
                'updated_at'  => now(),
            ]);
        }

        if (!DB::table('ai_engines')->where('provider', 'dicloak-gpt')->exists()) {
            DB::table('ai_engines')->insert([
                'name'        => 'DICloak GPT',
                'provider'    => 'dicloak-gpt',
                'tool_type'   => 'llm',
                'config_json' => json_encode(['profile_id' => null, 'tunnel_url' => null, 'note' => 'Perfil DICloak com GPT Plus. Ativar apos mapear profile_id.']),
                'priority'    => 10,
                'is_active'   => false,
                'created_at'  => now(),
                'updated_at'  => now(),
            ]);
        }

        if (!DB::table('ai_engines')->where('provider', 'dicloak-image')->exists()) {
            DB::table('ai_engines')->insert([
                'name'        => 'DICloak Image',
                'provider'    => 'dicloak-image',
                'tool_type'   => 'image',
                'config_json' => json_encode(['profile_id' => null, 'tunnel_url' => null, 'note' => 'Perfil DICloak com Midjourney/DALL-E. Ativar apos mapear profile_id.']),
                'priority'    => 10,
                'is_active'   => false,
                'created_at'  => now(),
                'updated_at'  => now(),
            ]);
        }

        if (!DB::table('ai_engines')->where('provider', 'dicloak-scraping')->exists()) {
            DB::table('ai_engines')->insert([
                'name'        => 'Kalodata Scraping',
                'provider'    => 'dicloak-scraping',
                'tool_type'   => 'scraping',
                'config_json' => json_encode(['profile_id' => null, 'tunnel_url' => null, 'note' => 'Perfil DICloak Kalodata. Ativar apos SEL-426 mapear profile_id.']),
                'priority'    => 10,
                'is_active'   => false,
                'created_at'  => now(),
                'updated_at'  => now(),
            ]);
        }

        if (DB::table('ai_engines')->where('provider', 'dicloak-scraping')->count() < 2) {
            DB::table('ai_engines')->insert([
                'name'        => 'Kalodata Scraping 2',
                'provider'    => 'dicloak-scraping',
                'tool_type'   => 'scraping',
                'config_json' => json_encode(['profile_id' => null, 'tunnel_url' => null, 'note' => 'Perfil DICloak Kalodata 2.']),
                'priority'    => 11,
                'is_active'   => false,
                'created_at'  => now(),
                'updated_at'  => now(),
            ]);
        }
    }

    public function down(): void
    {
        DB::table('ai_engines')
            ->whereIn('provider', ['openai-direct', 'dicloak-gpt', 'dicloak-image', 'dicloak-scraping'])
            ->delete();

        Schema::table('ai_engines', function (Blueprint $table) {
            $table->dropColumn('tool_type');
        });

        Schema::rename('ai_engines', 'video_engines');
    }
};
