<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * HUB-079: Adiciona unique constraint composta (platform, shop_id) em marketplace_accounts.
     * Remove duplicatas antes de criar o constraint (caso existam).
     */
    public function up(): void
    {
        // Remover duplicatas mantendo o registro mais recente
        $dups = DB::table('marketplace_accounts')
            ->whereNotNull('shop_id')
            ->select('platform', 'shop_id', DB::raw('MAX(id) as keep_id'), DB::raw('COUNT(*) as cnt'))
            ->groupBy('platform', 'shop_id')
            ->having('cnt', '>', 1)
            ->get();

        foreach ($dups as $dup) {
            DB::table('marketplace_accounts')
                ->where('platform', $dup->platform)
                ->where('shop_id', $dup->shop_id)
                ->where('id', '<>', $dup->keep_id)
                ->delete();
        }

        Schema::table('marketplace_accounts', function (Blueprint $table) {
            // Unique composta: plataforma + shop_id (um shop_id so pode existir uma vez por plataforma)
            $table->unique(['platform', 'shop_id'], 'uk_marketplace_accounts_platform_shop_id');
        });
    }

    public function down(): void
    {
        Schema::table('marketplace_accounts', function (Blueprint $table) {
            $table->dropUnique('uk_marketplace_accounts_platform_shop_id');
        });
    }
};
