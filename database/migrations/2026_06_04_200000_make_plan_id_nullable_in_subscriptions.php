<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table("subscriptions", function (Blueprint $table) {
            // Permite subscription sem plano (trialing sem plano vinculado)
            $table->unsignedBigInteger("plan_id")->nullable()->change();
        });
    }

    public function down(): void
    {
        Schema::table("subscriptions", function (Blueprint $table) {
            $table->unsignedBigInteger("plan_id")->nullable(false)->change();
        });
    }
};
