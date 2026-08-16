<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** SEL-490 QA — confirmação do cliente "o produto do vídeo está certo?". */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('video_feedback') && ! Schema::hasColumn('video_feedback', 'produto_correto')) {
            Schema::table('video_feedback', function (Blueprint $t) {
                $t->boolean('produto_correto')->nullable()->after('hook_type');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('video_feedback', 'produto_correto')) {
            Schema::table('video_feedback', fn (Blueprint $t) => $t->dropColumn('produto_correto'));
        }
    }
};
