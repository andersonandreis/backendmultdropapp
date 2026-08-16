<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * SEL-267 — histórico de campanhas de push notification enviadas pelo admin.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('push_campaigns', function (Blueprint $t) {
            $t->id();
            $t->string('titulo', 200);
            $t->string('body', 300);
            $t->string('url', 500)->nullable();
            $t->string('image_url', 500)->nullable();
            $t->enum('segment_type', ['all', 'plan', 'niche', 'recent'])->default('all');
            $t->string('segment_value', 100)->nullable();
            $t->timestamp('scheduled_at')->nullable();
            $t->timestamp('sent_at')->nullable();
            $t->integer('sent_count')->default(0);
            $t->integer('failed_count')->default(0);
            $t->integer('clicked_count')->default(0);
            $t->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $t->timestamps();
            $t->index(['sent_at', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('push_campaigns');
    }
};
