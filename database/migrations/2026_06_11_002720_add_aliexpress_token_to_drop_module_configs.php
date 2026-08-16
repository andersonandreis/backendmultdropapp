<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Adiciona campos de token AliExpress per-client ao drop_module_configs.
     * Permite que cada cliente conecte sua propria conta AliExpress via OAuth.
     */
    public function up(): void
    {
        Schema::table('drop_module_configs', function (Blueprint $table) {
            // Token OAuth AliExpress por cliente (criptografado com encrypt())
            $table->text('aliexpress_access_token')->nullable()->after('stripe_account_status');
            $table->text('aliexpress_refresh_token')->nullable()->after('aliexpress_access_token');
            $table->timestamp('aliexpress_token_expires_at')->nullable()->after('aliexpress_refresh_token');
        });
    }

    public function down(): void
    {
        Schema::table('drop_module_configs', function (Blueprint $table) {
            $table->dropColumn([
                'aliexpress_access_token',
                'aliexpress_refresh_token',
                'aliexpress_token_expires_at',
            ]);
        });
    }
};
