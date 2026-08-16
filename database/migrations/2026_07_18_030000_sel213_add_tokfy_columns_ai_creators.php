<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * SEL-213 — adiciona colunas Tokfy em ai_creators
 *
 * Colunas novas: gmv, following, likes_count, commission_items, country
 * Enum source: adiciona 'tokfy_real'
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('ai_creators', function (Blueprint $table) {
            $table->decimal('gmv', 15, 2)->nullable()->after('estimated_revenue');
            $table->unsignedInteger('following')->nullable()->after('followers');
            $table->unsignedBigInteger('likes_count')->nullable()->after('following');
            $table->unsignedInteger('commission_items')->nullable()->after('gmv');
            $table->string('country', 2)->default('BR')->after('commission_items');
        });

        \DB::statement("ALTER TABLE ai_creators MODIFY COLUMN source ENUM('tokfy', 'tokfy_real', 'scrape', 'manual') DEFAULT 'manual'");
    }

    public function down(): void
    {
        Schema::table('ai_creators', function (Blueprint $table) {
            $table->dropColumn(['gmv', 'following', 'likes_count', 'commission_items', 'country']);
        });

        \DB::statement("ALTER TABLE ai_creators MODIFY COLUMN source ENUM('tokfy', 'scrape', 'manual') DEFAULT 'manual'");
    }
};
