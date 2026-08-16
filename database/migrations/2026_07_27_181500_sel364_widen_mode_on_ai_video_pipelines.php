<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

// SEL-364: mode varchar(20) estourava com 'studio_animar_produto' (21 chars).
// Guardada com hasTable: só o backend seller.global tem ai_video_pipelines.
return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('ai_video_pipelines')) {
            return;
        }
        DB::statement("ALTER TABLE ai_video_pipelines MODIFY mode VARCHAR(40) NOT NULL DEFAULT 'perfect'");
    }

    public function down(): void
    {
        if (!Schema::hasTable('ai_video_pipelines')) {
            return;
        }
        DB::statement("ALTER TABLE ai_video_pipelines MODIFY mode VARCHAR(20) NOT NULL DEFAULT 'perfect'");
    }
};
