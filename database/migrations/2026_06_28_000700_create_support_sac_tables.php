<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('support_tickets', function (Blueprint $t) {
            if (!Schema::hasColumn('support_tickets', 'supplier_id')) {
                $t->unsignedBigInteger('supplier_id')->nullable()->after('client_id')->index();
            }
            if (!Schema::hasColumn('support_tickets', 'department_id')) {
                $t->unsignedBigInteger('department_id')->nullable()->after('category')->index();
            }
            if (!Schema::hasColumn('support_tickets', 'topic_id')) {
                $t->unsignedBigInteger('topic_id')->nullable()->after('department_id')->index();
            }
            if (!Schema::hasColumn('support_tickets', 'operator_user_id')) {
                $t->unsignedBigInteger('operator_user_id')->nullable()->after('topic_id')->index();
            }
            if (!Schema::hasColumn('support_tickets', 'first_response_at')) {
                $t->timestamp('first_response_at')->nullable();
            }
        });

        Schema::create('support_departments', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('supplier_id')->index();
            $t->string('name', 100);
            $t->string('description', 255)->nullable();
            $t->string('color', 20)->nullable();
            $t->boolean('active')->default(true);
            $t->timestamps();
        });

        Schema::create('support_topics', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('supplier_id')->index();
            $t->unsignedBigInteger('department_id')->index();
            $t->string('name', 100);
            $t->string('description', 255)->nullable();
            $t->boolean('active')->default(true);
            $t->timestamps();
        });

        Schema::create('support_quick_replies', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('supplier_id')->index();
            $t->string('title', 100);
            $t->text('body');
            $t->unsignedBigInteger('department_id')->nullable()->index();
            $t->boolean('active')->default(true);
            $t->timestamps();
        });

        Schema::create('support_operators', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('supplier_id')->index();
            $t->unsignedBigInteger('user_id')->index();
            $t->json('department_ids')->nullable();
            $t->boolean('online')->default(false);
            $t->boolean('active')->default(true);
            $t->timestamps();
            $t->unique(['supplier_id', 'user_id'], 'supop_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('support_operators');
        Schema::dropIfExists('support_quick_replies');
        Schema::dropIfExists('support_topics');
        Schema::dropIfExists('support_departments');
        Schema::table('support_tickets', function (Blueprint $t) {
            foreach (['supplier_id','department_id','topic_id','operator_user_id','first_response_at'] as $col) {
                if (Schema::hasColumn('support_tickets', $col)) {
                    $t->dropColumn($col);
                }
            }
        });
    }
};
