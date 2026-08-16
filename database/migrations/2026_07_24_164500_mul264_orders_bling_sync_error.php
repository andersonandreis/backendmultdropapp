<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
return new class extends Migration {
    public function up(): void {
        Schema::table('orders', function (Blueprint $t) {
            if (!Schema::hasColumn('orders','bling_sync_error')) $t->text('bling_sync_error')->nullable();
            if (!Schema::hasColumn('orders','bling_sync_attempted_at')) $t->timestamp('bling_sync_attempted_at')->nullable();
        });
    }
    public function down(): void {}
};
