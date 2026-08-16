<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * SEL-227 hotfix — screen_hash veio como VARCHAR(32) na migration original
 * mas a service gera SHA-256 (64 chars) → SQLSTATE[22001] no primeiro POST.
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::table('device_fingerprints', function (Blueprint $t) {
            $t->string('screen_hash', 64)->nullable()->change();
        });
    }

    public function down(): void
    {
        Schema::table('device_fingerprints', function (Blueprint $t) {
            $t->string('screen_hash', 32)->nullable()->change();
        });
    }
};
