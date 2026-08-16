<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * SEL-195 Gap D: adiciona campo `description` em video_avatars
 * para injeção no prompt Kling via buildPreviewPrompt() no frontend.
 *
 * Formato esperado: "woman, professional style, medium skin tone"
 * Usado pelo RefillAvatarPoolJob ao gerar novos avatares automaticamente.
 * Seeds existentes (SEL-120 F1) podem ser atualizados manualmente ou via tinker.
 */
return new class extends Migration
{
    public function up(): void
    {
        // Guard: tabela so existe no seller.global (repo compartilhado 7 backends)
        if (! Schema::hasTable('video_avatars')) {
            return;
        }

        Schema::table('video_avatars', function (Blueprint $table) {
            if (!Schema::hasColumn('video_avatars', 'description')) {
                $table->string('description', 255)->nullable()->after('style');
            }
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('video_avatars')) {
            return;
        }

        Schema::table('video_avatars', function (Blueprint $table) {
            if (Schema::hasColumn('video_avatars', 'description')) {
                $table->dropColumn('description');
            }
        });
    }
};
