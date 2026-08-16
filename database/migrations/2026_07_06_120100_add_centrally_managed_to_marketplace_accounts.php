<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasColumn('marketplace_accounts', 'centrally_managed')) {
            return;
        }

        Schema::table('marketplace_accounts', function (Blueprint $table) {
            // NOV-181: true = a cadeia de tokens desta conta pertence ao hub central;
            // esta instalacao apenas espelha o token e NUNCA renova localmente.
            $table->boolean('centrally_managed')->default(false)->after('status');
        });
    }

    public function down(): void
    {
        if (! Schema::hasColumn('marketplace_accounts', 'centrally_managed')) {
            return;
        }

        Schema::table('marketplace_accounts', function (Blueprint $table) {
            $table->dropColumn('centrally_managed');
        });
    }
};
