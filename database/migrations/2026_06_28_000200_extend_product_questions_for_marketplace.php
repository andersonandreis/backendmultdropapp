<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('product_questions', function (Blueprint $t) {
            if (!Schema::hasColumn('product_questions', 'marketplace_account_id')) {
                $t->unsignedBigInteger('marketplace_account_id')->nullable()->after('supplier_id')->index();
            }
            if (!Schema::hasColumn('product_questions', 'marketplace')) {
                $t->string('marketplace', 30)->nullable()->after('marketplace_account_id')->index();
            }
            if (!Schema::hasColumn('product_questions', 'marketplace_question_id')) {
                $t->string('marketplace_question_id', 100)->nullable()->after('marketplace')->index();
            }
            if (!Schema::hasColumn('product_questions', 'marketplace_item_id')) {
                $t->string('marketplace_item_id', 100)->nullable()->after('marketplace_question_id')->index();
            }
            if (!Schema::hasColumn('product_questions', 'buyer_name')) {
                $t->string('buyer_name', 200)->nullable()->after('marketplace_item_id');
            }
            if (!Schema::hasColumn('product_questions', 'buyer_external_id')) {
                $t->string('buyer_external_id', 100)->nullable()->after('buyer_name');
            }
            if (!Schema::hasColumn('product_questions', 'status')) {
                $t->string('status', 30)->default('pending')->after('answer')->index();
            }
            if (!Schema::hasColumn('product_questions', 'asked_at')) {
                $t->timestamp('asked_at')->nullable()->after('status');
            }
            if (!Schema::hasColumn('product_questions', 'failure_reason')) {
                $t->text('failure_reason')->nullable()->after('answered_at');
            }
        });
    }

    public function down(): void
    {
        Schema::table('product_questions', function (Blueprint $t) {
            foreach (['marketplace_account_id','marketplace','marketplace_question_id','marketplace_item_id','buyer_name','buyer_external_id','status','asked_at','failure_reason'] as $col) {
                if (Schema::hasColumn('product_questions', $col)) {
                    $t->dropColumn($col);
                }
            }
        });
    }
};
