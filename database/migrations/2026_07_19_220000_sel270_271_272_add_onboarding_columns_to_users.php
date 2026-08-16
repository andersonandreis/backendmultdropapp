<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * SEL-270/271/272 Ruan 19/07 15:09:
 * - push_activated_at + push_declined_count + push_fallback_channel (SEL-270 gate)
 * - network_group_redirected_at (SEL-271 timer 3min)
 * - phone_e164 + phone_verified_at + firebase_uid (SEL-272 Firebase Phone Auth)
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $t) {
            if (!Schema::hasColumn('users', 'push_activated_at')) {
                $t->timestamp('push_activated_at')->nullable()->after('remember_token');
            }
            if (!Schema::hasColumn('users', 'push_declined_count')) {
                $t->unsignedTinyInteger('push_declined_count')->default(0)->after('push_activated_at');
            }
            if (!Schema::hasColumn('users', 'push_fallback_channel')) {
                $t->string('push_fallback_channel', 20)->nullable()->after('push_declined_count');
            }
            if (!Schema::hasColumn('users', 'network_group_redirected_at')) {
                $t->timestamp('network_group_redirected_at')->nullable()->after('push_fallback_channel');
            }
            if (!Schema::hasColumn('users', 'phone_e164')) {
                $t->string('phone_e164', 20)->nullable()->after('network_group_redirected_at');
                $t->index('phone_e164');
            }
            if (!Schema::hasColumn('users', 'phone_verified_at')) {
                $t->timestamp('phone_verified_at')->nullable()->after('phone_e164');
            }
            if (!Schema::hasColumn('users', 'firebase_uid')) {
                $t->string('firebase_uid', 128)->nullable()->after('phone_verified_at');
                $t->index('firebase_uid');
            }
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $t) {
            foreach (['push_activated_at', 'push_declined_count', 'push_fallback_channel', 'network_group_redirected_at', 'phone_e164', 'phone_verified_at', 'firebase_uid'] as $c) {
                if (Schema::hasColumn('users', $c)) {
                    $t->dropColumn($c);
                }
            }
        });
    }
};
