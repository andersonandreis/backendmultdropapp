<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('clients', function (Blueprint $table) {
            if (! Schema::hasColumn('clients', 'legacy_password_type')) {
                $table->string('legacy_password_type', 40)->nullable()->after('legacy_id_login')
                    ->comment('plaintext_migrated | sha256_needs_reset | no_password_needs_reset');
            }
            if (! Schema::hasColumn('clients', 'legacy_sha256_hash')) {
                $table->string('legacy_sha256_hash', 64)->nullable()->after('legacy_password_type')
                    ->comment('Hash SHA-256 original do legado, preservado para verificacao pos-migracao');
            }
        });
    }

    public function down(): void
    {
        Schema::table('clients', function (Blueprint $table) {
            if (Schema::hasColumn('clients', 'legacy_sha256_hash')) {
                $table->dropColumn('legacy_sha256_hash');
            }
            if (Schema::hasColumn('clients', 'legacy_password_type')) {
                $table->dropColumn('legacy_password_type');
            }
        });
    }
};
