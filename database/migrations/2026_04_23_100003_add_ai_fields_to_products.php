<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->string('ai_title', 100)->nullable()->after('name');
            $table->text('ai_description')->nullable()->after('description');
            $table->json('ai_bullet_points')->nullable()->after('ai_description');
        });
    }

    public function down(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->dropColumn(['ai_title', 'ai_description', 'ai_bullet_points']);
        });
    }
};
