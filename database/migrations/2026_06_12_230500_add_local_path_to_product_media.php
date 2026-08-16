<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('product_media', function (Blueprint $table) {
            $table->string('original_url', 500)->nullable()->after('url');
            $table->string('local_path', 255)->nullable()->after('original_url');
            $table->index('local_path');
        });
    }

    public function down(): void
    {
        Schema::table('product_media', function (Blueprint $table) {
            $table->dropIndex(['local_path']);
            $table->dropColumn(['original_url', 'local_path']);
        });
    }
};
