<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('email_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('email_type')->default('welcome'); // welcome, recovery, etc
            $table->string('to_email');
            $table->uuid('token')->unique();
            $table->string('status')->default('queued'); // queued, sent, delivered, opened, clicked, failed, bounced
            $table->timestamp('sent_at')->nullable();
            $table->timestamp('opened_at')->nullable();
            $table->integer('opened_count')->default(0);
            $table->timestamp('clicked_at')->nullable();
            $table->integer('click_count')->default(0);
            $table->string('clicked_link')->nullable();
            $table->text('failed_reason')->nullable();
            $table->timestamps();

            $table->index(['user_id', 'email_type']);
            $table->index('status');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('email_logs');
    }
};
