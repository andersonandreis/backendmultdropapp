<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('client_products', function (Blueprint $table) {
            $table->string('external_listing_url', 500)->nullable()->after('external_listing_id');
        });
    }

    public function down(): void
    {
        Schema::table('client_products', function (Blueprint $table) {
            $table->dropColumn('external_listing_url');
        });
    }
};
