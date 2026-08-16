<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('affiliates', function (Blueprint $table) {
            $table->string('application_name')->nullable()->after('user_id');
            $table->string('application_email')->nullable()->after('application_name');
            $table->string('application_phone', 50)->nullable()->after('application_email');
            $table->string('application_instagram', 100)->nullable()->after('application_phone');
            $table->string('application_tiktok', 100)->nullable()->after('application_instagram');
            $table->text('application_motivation')->nullable()->after('application_tiktok');
            $table->enum('approval_status', ['pending', 'approved', 'rejected', 'suspended'])
                  ->default('pending')->after('application_motivation');
            $table->timestamp('approved_at')->nullable()->after('approval_status');
            $table->unsignedBigInteger('approved_by')->nullable()->after('approved_at');
            $table->text('rejection_reason')->nullable()->after('approved_by');
            $table->unsignedInteger('max_ai_videos_per_month')->default(0)->after('rejection_reason');
            $table->string('granted_plan_slug', 50)->nullable()->after('max_ai_videos_per_month');
            $table->json('perks')->nullable()->after('granted_plan_slug');
            $table->string('custom_referral_slug', 30)->nullable()->unique()->after('perks');
        });

        Schema::table('affiliate_referrals', function (Blueprint $table) {
            $table->string('landing_url', 500)->nullable()->after('user_agent');
            $table->string('utm_source', 100)->nullable()->after('landing_url');
        });

        Schema::table('affiliate_commissions', function (Blueprint $table) {
            $table->enum('event_type', ['signup', 'upgrade', 'recurring'])
                  ->default('upgrade')->after('notes');
            $table->string('plan_slug', 50)->nullable()->after('event_type');
        });
    }

    public function down(): void
    {
        Schema::table('affiliate_commissions', function (Blueprint $table) {
            $table->dropColumn(['event_type', 'plan_slug']);
        });
        Schema::table('affiliate_referrals', function (Blueprint $table) {
            $table->dropColumn(['landing_url', 'utm_source']);
        });
        Schema::table('affiliates', function (Blueprint $table) {
            $table->dropColumn([
                'application_name', 'application_email', 'application_phone',
                'application_instagram', 'application_tiktok', 'application_motivation',
                'approval_status', 'approved_at', 'approved_by', 'rejection_reason',
                'max_ai_videos_per_month', 'granted_plan_slug', 'perks', 'custom_referral_slug',
            ]);
        });
    }
};
