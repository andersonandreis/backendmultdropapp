<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('clients', function (Blueprint $table) {
            $table->boolean('auto_pay_from_wallet')->default(false)->after('id');
            $table->decimal('auto_pay_min_balance', 10, 2)->default(0)->after('auto_pay_from_wallet');
        });

        Schema::table('orders', function (Blueprint $table) {
            $table->timestamp('wallet_paid_at')->nullable()->after('updated_at');
            $table->unsignedBigInteger('wallet_transaction_id')->nullable()->after('wallet_paid_at');
        });
    }

    public function down(): void
    {
        Schema::table('clients', function (Blueprint $table) {
            $table->dropColumn(['auto_pay_from_wallet', 'auto_pay_min_balance']);
        });

        Schema::table('orders', function (Blueprint $table) {
            $table->dropColumn(['wallet_paid_at', 'wallet_transaction_id']);
        });
    }
};
