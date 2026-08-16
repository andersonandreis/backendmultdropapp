<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * NOV-120 — Status returned em orders + tabela order_returns (1:N por item).
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            if (!Schema::hasColumn('orders', 'returned_at')) {
                $table->timestamp('returned_at')->nullable()->after('shipped_at');
            }
            if (!Schema::hasColumn('orders', 'return_reason')) {
                $table->text('return_reason')->nullable()->after('returned_at');
            }
            if (!Schema::hasColumn('orders', 'has_partial_return')) {
                $table->boolean('has_partial_return')->default(false)->after('return_reason');
            }
        });

        if (!Schema::hasTable('order_returns')) {
            Schema::create('order_returns', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('order_id');
                $table->unsignedBigInteger('order_item_id');
                $table->integer('qty_returned');
                $table->text('reason')->nullable();
                $table->unsignedBigInteger('user_id')->nullable();
                $table->timestamp('created_at')->useCurrent();

                $table->index(['order_id', 'created_at'], 'idx_or_order');
                $table->foreign('order_id')->references('id')->on('orders')->cascadeOnDelete();
                $table->foreign('order_item_id')->references('id')->on('order_items')->cascadeOnDelete();
                $table->foreign('user_id')->references('id')->on('users')->nullOnDelete();
            });
        }
    }

    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->dropColumn(['returned_at', 'return_reason', 'has_partial_return']);
        });
        Schema::dropIfExists('order_returns');
    }
};
