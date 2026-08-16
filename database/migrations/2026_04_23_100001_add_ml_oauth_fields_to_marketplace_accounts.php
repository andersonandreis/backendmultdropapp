<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('marketplace_accounts', function (Blueprint $table) {
            $table->string('ml_user_id')->nullable()->after('seller_id');
            $table->text('ml_access_token')->nullable()->after('ml_user_id');   // stored encrypted
            $table->text('ml_refresh_token')->nullable()->after('ml_access_token'); // stored encrypted
            $table->timestamp('ml_token_expires_at')->nullable()->after('ml_refresh_token');
        });
    }

    public function down(): void
    {
        Schema::table('marketplace_accounts', function (Blueprint $table) {
            $table->dropColumn(['ml_user_id', 'ml_access_token', 'ml_refresh_token', 'ml_token_expires_at']);
        });
    }
};
