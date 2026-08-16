<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('audit_logs', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('user_id')->nullable()->index();
            $t->string('event', 100)->index();
            $t->string('auditable_type')->nullable();
            $t->unsignedBigInteger('auditable_id')->nullable();
            $t->json('metadata')->nullable();
            $t->string('ip', 45)->nullable();
            $t->timestamps();
            $t->index(['auditable_type', 'auditable_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('audit_logs');
    }
};
