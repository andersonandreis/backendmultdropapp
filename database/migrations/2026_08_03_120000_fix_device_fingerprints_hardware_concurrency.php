<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * SEL-423: Fix SQLSTATE[22003] — hardware_concurrency era TINYINT (max 127 signed)
 * mas Googlebot envia valores como 245 que excedem o limite.
 * SMALLINT suporta ate 32767 — mais que suficiente para hardware_concurrency real.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('device_fingerprints', function (Blueprint $table) {
            $table->smallInteger('hardware_concurrency')->nullable()->change();
            $table->smallInteger('device_memory')->nullable()->change();
        });
    }

    public function down(): void
    {
        Schema::table('device_fingerprints', function (Blueprint $table) {
            $table->tinyInteger('hardware_concurrency')->nullable()->change();
            $table->tinyInteger('device_memory')->nullable()->change();
        });
    }
};
