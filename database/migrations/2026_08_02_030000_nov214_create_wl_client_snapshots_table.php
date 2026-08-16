<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('wl_client_snapshots', function (Blueprint $table) {
            $table->id();
            $table->unsignedTinyInteger('empresa_id')->index();
            $table->string('wl_database', 60);
            $table->date('snapshot_date')->index();
            $table->unsignedBigInteger('client_id');
            $table->string('email', 191)->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamp('blocked_at')->nullable();
            $table->timestamps();

            $table->unique(['empresa_id', 'snapshot_date', 'client_id'], 'wl_snapshots_unique');
            $table->index(['empresa_id', 'snapshot_date']);
            $table->index(['email', 'snapshot_date']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('wl_client_snapshots');
    }
};
