<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('affiliate_referrals', function (Blueprint $table) {
            $table->id();
            $table->foreignId('affiliate_id')->constrained('affiliates')->onDelete('cascade');
            $table->foreignId('referred_client_id')->nullable()->constrained('clients')->onDelete('set null');
            $table->string('referral_code', 12);
            $table->string('ip_address', 45)->nullable();
            $table->text('user_agent')->nullable();
            $table->timestamp('attributed_at');
            $table->timestamp('converted_at')->nullable();
            $table->enum('status', ['pending', 'converted', 'expired'])->default('pending');
            $table->timestamps();

            $table->index(['affiliate_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('affiliate_referrals');
    }
};
