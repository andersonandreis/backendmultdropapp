<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Guard: tabela pode ja existir da migration 2024_01_01_000029
        if (Schema::hasTable("sync_logs")) {
            return;
        }
        Schema::create("sync_logs", function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger("client_product_id");
            $table->string("action", 50);
            $table->string("status", 20);
            $table->text("message");
            $table->text("raw_response")->nullable();
            $table->timestamp("created_at");
            $table->index("client_product_id");
            $table->index("created_at");
        });
    }

    public function down(): void
    {
        Schema::dropIfExists("sync_logs");
    }
};
