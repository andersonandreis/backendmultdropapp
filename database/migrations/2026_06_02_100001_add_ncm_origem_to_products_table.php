<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('products', function (Blueprint $table) {
            if (!Schema::hasColumn('products', 'ncm')) {
                $table->string('ncm', 20)->nullable()->after('length_cm');
            }
            if (!Schema::hasColumn('products', 'origem')) {
                $table->string('origem', 10)->nullable()->after('ncm');
            }
        });
    }

    public function down(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->dropColumnIfExists('ncm');
            $table->dropColumnIfExists('origem');
        });
    }
};
