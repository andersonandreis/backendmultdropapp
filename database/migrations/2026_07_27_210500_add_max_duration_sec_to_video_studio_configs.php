<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Guard SEL-362: tabela só existe no tenant seller.global — migrate dos
        // outros backends não pode quebrar.
        if (!Schema::hasTable('video_studio_configs')) return;
        if (Schema::hasColumn('video_studio_configs', 'max_duration_sec')) return;

        Schema::table('video_studio_configs', function (Blueprint $table) {
            $table->unsignedSmallInteger('max_duration_sec')->default(10)->after('client_price_cents');
        });
    }

    public function down(): void
    {
        if (!Schema::hasTable('video_studio_configs')) return;
        if (!Schema::hasColumn('video_studio_configs', 'max_duration_sec')) return;

        Schema::table('video_studio_configs', function (Blueprint $table) {
            $table->dropColumn('max_duration_sec');
        });
    }
};
