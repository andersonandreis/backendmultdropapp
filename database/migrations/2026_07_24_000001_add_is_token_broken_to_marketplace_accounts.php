<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasColumn('marketplace_accounts', 'is_token_broken')) {
            return;
        }

        Schema::table('marketplace_accounts', function (Blueprint $table) {
            $table->tinyInteger('is_token_broken')->default(0)->after('needs_reauth');
            $table->string('token_broken_reason', 255)->nullable()->after('is_token_broken');
            $table->timestamp('token_broken_at')->nullable()->after('token_broken_reason');
        });
    }

    public function down(): void
    {
        Schema::table('marketplace_accounts', function (Blueprint $table) {
            foreach (['is_token_broken', 'token_broken_reason', 'token_broken_at'] as $col) {
                if (Schema::hasColumn('marketplace_accounts', $col)) {
                    $table->dropColumn($col);
                }
            }
        });
    }
};
