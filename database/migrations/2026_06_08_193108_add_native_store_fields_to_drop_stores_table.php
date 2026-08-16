<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('drop_stores', function (Blueprint $table) {
            $table->enum('platform', ['shopify', 'native'])->default('shopify')->after('client_id');
            $table->string('store_slug', 100)->nullable()->unique()->after('platform');
            $table->string('custom_domain', 255)->nullable()->after('store_slug');
            $table->string('store_display_name', 255)->nullable()->after('custom_domain');
            $table->string('primary_color', 7)->nullable()->default('#3B82F6')->after('store_display_name');
            $table->string('logo_url', 500)->nullable()->after('primary_color');
            $table->string('banner_url', 500)->nullable()->after('logo_url');
            $table->boolean('is_published')->default(false)->after('banner_url');
            $table->timestamp('published_at')->nullable()->after('is_published');
        });
    }

    public function down(): void
    {
        Schema::table('drop_stores', function (Blueprint $table) {
            $table->dropColumn([
                'platform', 'store_slug', 'custom_domain', 'store_display_name',
                'primary_color', 'logo_url', 'banner_url', 'is_published', 'published_at',
            ]);
        });
    }
};
