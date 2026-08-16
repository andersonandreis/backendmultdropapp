<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * SEL-361 Fase A — Refatora client_video_avatars para suportar múltiplos
 * avatares exclusivos por cliente (anti-banimento TikTok).
 *
 * Estrutura anterior: 1 linha por cliente (unique client_id), ligada ao pool.
 * Estrutura nova: N linhas por cliente, cada uma com source e flag is_exclusive.
 *
 * SEL-362: tabela existe SÓ no sellerapp — guards obrigatórios pro repo
 * compartilhado (7 backends) não quebrar o migrate.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('client_video_avatars')) {
            return;
        }

        if (Schema::hasIndex('client_video_avatars', 'client_video_avatars_client_id_unique')) {
            Schema::table('client_video_avatars', function (Blueprint $table) {
                $table->dropUnique(['client_id']);
            });
        }

        Schema::table('client_video_avatars', function (Blueprint $table) {
            if (! Schema::hasColumn('client_video_avatars', 'source')) {
                $table->string('source', 32)->default('pool_shared')->after('label')
                    ->comment('upload|generated_exclusive|ready_player_me|pool_shared');
            }
            if (! Schema::hasColumn('client_video_avatars', 'is_exclusive_to_client')) {
                $table->boolean('is_exclusive_to_client')->default(false)->after('source');
            }
            if (! Schema::hasColumn('client_video_avatars', 'is_active')) {
                $table->boolean('is_active')->default(true)->after('is_exclusive_to_client');
            }
            if (! Schema::hasColumn('client_video_avatars', 'generation_prompt')) {
                $table->text('generation_prompt')->nullable()->after('is_active')
                    ->comment('Prompt usado pra gerar este avatar (auditoria)');
            }
            if (! Schema::hasColumn('client_video_avatars', 'generation_seed')) {
                $table->string('generation_seed', 64)->nullable()->after('generation_prompt')
                    ->comment('Seed pseudo-random para garantir unicidade');
            }
        });

        if (! Schema::hasIndex('client_video_avatars', 'cva_client_active_source')) {
            Schema::table('client_video_avatars', function (Blueprint $table) {
                $table->index(['client_id', 'is_active', 'source'], 'cva_client_active_source');
            });
        }
    }

    public function down(): void
    {
        if (! Schema::hasTable('client_video_avatars')) {
            return;
        }

        Schema::table('client_video_avatars', function (Blueprint $table) {
            $table->dropIndex('cva_client_active_source');
            $table->dropColumn(['source', 'is_exclusive_to_client', 'is_active', 'generation_prompt', 'generation_seed']);
            $table->unique('client_id');
        });
    }
};
