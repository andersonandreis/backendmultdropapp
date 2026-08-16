<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // MUL-204: indice composto pra MUL-205 buscar pedidos ML em handling.
        $exists = collect(DB::select('SHOW INDEX FROM orders WHERE Key_name = ?', ['idx_source_proc_status']))->isNotEmpty();
        if (! $exists) {
            Schema::table('orders', function ($t) {
                $t->index(['source', 'order_processing_status'], 'idx_source_proc_status');
            });
        }
    }

    public function down(): void
    {
        $exists = collect(DB::select('SHOW INDEX FROM orders WHERE Key_name = ?', ['idx_source_proc_status']))->isNotEmpty();
        if ($exists) {
            Schema::table('orders', function ($t) {
                $t->dropIndex('idx_source_proc_status');
            });
        }
    }
};
