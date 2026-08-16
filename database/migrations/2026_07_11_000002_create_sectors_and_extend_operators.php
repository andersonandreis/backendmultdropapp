<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('sectors')) {
            Schema::create('sectors', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('supplier_id')->index();
                $table->string('name');
                $table->text('description')->nullable();
                $table->boolean('is_active')->default(true);
                $table->timestamps();
            });
        }
        Schema::table('operators', function (Blueprint $table) {
            if (!Schema::hasColumn('operators', 'sector_id')) {
                $table->unsignedBigInteger('sector_id')->nullable()->index()->after('supplier_id');
            }
            if (!Schema::hasColumn('operators', 'email')) {
                $table->string('email')->nullable()->after('name');
            }
            if (!Schema::hasColumn('operators', 'permissions')) {
                $table->json('permissions')->nullable()->after('badge_code');
            }
        });
    }

    public function down(): void
    {
        Schema::table('operators', function (Blueprint $table) {
            $table->dropColumn(['sector_id', 'email', 'permissions']);
        });
        Schema::dropIfExists('sectors');
    }
};
