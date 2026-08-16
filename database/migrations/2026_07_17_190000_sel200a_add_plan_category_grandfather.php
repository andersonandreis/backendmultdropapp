<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

/**
 * SEL-200A: categoria em plans + grandfathered em subscriptions.
 * Nao corta ninguem — apenas marca 19 pagantes atuais + 761 free como legacy pra migrar depois.
 */
return new class extends Migration {
    public function up(): void
    {
        if (!Schema::hasColumn('plans', 'category')) {
            Schema::table('plans', function (Blueprint $t) {
                $t->string('category', 32)->default('legacy')->after('slug');
                $t->index('category');
            });
        }
        if (!Schema::hasColumn('subscriptions', 'is_grandfathered')) {
            Schema::table('subscriptions', function (Blueprint $t) {
                $t->boolean('is_grandfathered')->default(false)->after('status');
                $t->index('is_grandfathered');
            });
        }
        if (!Schema::hasColumn('subscriptions', 'needs_verification')) {
            Schema::table('subscriptions', function (Blueprint $t) {
                $t->boolean('needs_verification')->default(false)->after('is_grandfathered');
            });
        }
        // Trial_ends_at ja existe em subscriptions (Laravel default). Ver:
        if (!Schema::hasColumn('subscriptions', 'trial_ends_at')) {
            Schema::table('subscriptions', function (Blueprint $t) {
                $t->timestamp('trial_ends_at')->nullable()->after('needs_verification');
            });
        }

        // Categorizar planos legados existentes
        DB::table('plans')->whereIn('slug', ['start','pro','scaling'])->update(['category' => 'legacy_monthly']);
        DB::table('plans')->where('slug', 'tiktok_free')->update(['category' => 'legacy_free']);
        DB::table('plans')->where('slug', 'supplier_only')->update(['category' => 'fornecedores']);
        DB::table('plans')->where('slug', 'demo')->update(['category' => 'demo']);

        // Marcar todos os pagantes/free atuais como grandfathered
        DB::table('subscriptions')->whereIn('status', ['active','trialing'])->update(['is_grandfathered' => true]);
    }

    public function down(): void
    {
        Schema::table('plans', fn (Blueprint $t) => $t->dropColumn('category'));
        Schema::table('subscriptions', function (Blueprint $t) {
            $t->dropColumn(['is_grandfathered','needs_verification']);
        });
    }
};
