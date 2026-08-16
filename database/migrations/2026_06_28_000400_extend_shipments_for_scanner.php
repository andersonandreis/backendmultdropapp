<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('shipments', function (Blueprint $t) {
            if (!Schema::hasColumn('shipments', 'box_count')) {
                $t->unsignedInteger('box_count')->default(1)->after('total_checked');
            }
            if (!Schema::hasColumn('shipments', 'carrier')) {
                $t->string('carrier', 80)->nullable()->after('box_count');
            }
            if (!Schema::hasColumn('shipments', 'tracking_code')) {
                $t->string('tracking_code', 100)->nullable()->after('carrier');
            }
            if (!Schema::hasColumn('shipments', 'declared_value')) {
                $t->decimal('declared_value', 12, 2)->nullable()->after('tracking_code');
            }
            if (!Schema::hasColumn('shipments', 'marketplace')) {
                $t->string('marketplace', 40)->nullable()->after('declared_value');
            }
        });

        Schema::table('shipment_items', function (Blueprint $t) {
            if (!Schema::hasColumn('shipment_items', 'box_number')) {
                $t->unsignedInteger('box_number')->nullable()->after('quantity_received');
            }
            if (!Schema::hasColumn('shipment_items', 'scanned_at')) {
                $t->timestamp('scanned_at')->nullable()->after('box_number');
            }
            if (!Schema::hasColumn('shipment_items', 'scanned_by_user_id')) {
                $t->unsignedBigInteger('scanned_by_user_id')->nullable()->after('scanned_at');
            }
        });
    }

    public function down(): void
    {
        Schema::table('shipments', function (Blueprint $t) {
            foreach (['box_count','carrier','tracking_code','declared_value','marketplace'] as $col) {
                if (Schema::hasColumn('shipments', $col)) {
                    $t->dropColumn($col);
                }
            }
        });
        Schema::table('shipment_items', function (Blueprint $t) {
            foreach (['box_number','scanned_at','scanned_by_user_id'] as $col) {
                if (Schema::hasColumn('shipment_items', $col)) {
                    $t->dropColumn($col);
                }
            }
        });
    }
};
