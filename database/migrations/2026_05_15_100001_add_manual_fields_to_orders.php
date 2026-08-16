<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->string('manual_reason', 255)->nullable()->after('label_url');
            $table->unsignedBigInteger('manual_created_by')->nullable()->after('manual_reason');
            $table->foreign('manual_created_by')
                  ->references('id')
                  ->on('users')
                  ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->dropForeign(['manual_created_by']);
            $table->dropColumn(['manual_reason', 'manual_created_by']);
        });
    }
};

