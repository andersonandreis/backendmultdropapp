<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('client_products', function (Blueprint $table) {
            if (! Schema::hasColumn('client_products', 'legacy_id')) {
                $table->unsignedInteger('legacy_id')->nullable()->after('id')->index();
            }
        });
    }

    public function down(): void
    {
        Schema::table('client_products', function (Blueprint $table) {
            if (Schema::hasColumn('client_products', 'legacy_id')) {
                $table->dropColumn('legacy_id');
            }
        });
    }
};
