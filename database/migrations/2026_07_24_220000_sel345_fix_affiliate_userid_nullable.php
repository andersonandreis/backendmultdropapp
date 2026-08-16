<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * SEL-345 fix: user_id deve ser nullable em affiliates,
     * pois candidatos podem aplicar antes de ter conta no sistema.
     */
    public function up(): void
    {
        // Drop FK temporarily to alter column
        Schema::table('affiliates', function (Blueprint $table) {
            // Some MySQL drivers need the FK dropped first
            try {
                $table->dropForeign(['user_id']);
            } catch (\Throwable $e) {
                // FK may not exist or have different name; continue
            }
        });

        DB::statement('ALTER TABLE affiliates MODIFY user_id BIGINT UNSIGNED NULL');

        Schema::table('affiliates', function (Blueprint $table) {
            $table->foreign('user_id')->references('id')->on('users')->onDelete('set null');
        });
    }

    public function down(): void
    {
        // Reverse: make NOT NULL again (only if all rows have user_id filled)
        Schema::table('affiliates', function (Blueprint $table) {
            try { $table->dropForeign(['user_id']); } catch (\Throwable $e) {}
        });
        DB::statement('ALTER TABLE affiliates MODIFY user_id BIGINT UNSIGNED NOT NULL');
        Schema::table('affiliates', function (Blueprint $table) {
            $table->foreign('user_id')->references('id')->on('users')->onDelete('cascade');
        });
    }
};
