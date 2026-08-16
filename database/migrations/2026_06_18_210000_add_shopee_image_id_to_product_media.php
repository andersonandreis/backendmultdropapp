<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('product_media', function (Blueprint $table) {
            if (! Schema::hasColumn('product_media', 'shopee_image_id')) {
                $table->string('shopee_image_id')->nullable()->after('external_id');
            }
        });
    }

    public function down(): void
    {
        Schema::table('product_media', function (Blueprint $table) {
            if (Schema::hasColumn('product_media', 'shopee_image_id')) {
                $table->dropColumn('shopee_image_id');
            }
        });
    }
};
