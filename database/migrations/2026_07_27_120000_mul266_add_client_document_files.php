<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('clients', function (Blueprint $table) {
            $table->string('rg_front_file')->nullable()->after('rg');
            $table->string('rg_back_file')->nullable()->after('rg_front_file');
            $table->string('residence_proof_file')->nullable()->after('rg_back_file');
        });
    }

    public function down(): void
    {
        Schema::table('clients', function (Blueprint $table) {
            $table->dropColumn(['rg_front_file', 'rg_back_file', 'residence_proof_file']);
        });
    }
};
