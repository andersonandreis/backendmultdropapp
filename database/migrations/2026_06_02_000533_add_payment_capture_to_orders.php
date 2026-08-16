<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->decimal('captured_amount', 10, 2)->nullable()->after('paid_at');
            $table->timestamp('captured_at')->nullable()->after('captured_amount');
            $table->string('capture_source', 50)->nullable()->after('captured_at'); // 'shopee_escrow' | 'manual'
            $table->json('capture_payload')->nullable()->after('capture_source');
        });
    }

    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->dropColumn(['captured_amount', 'captured_at', 'capture_source', 'capture_payload']);
        });
    }
};
