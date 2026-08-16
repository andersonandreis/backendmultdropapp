<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create("tenant_supplier", function (Blueprint $table) {
            $table->char("tenant_id", 36);
            $table->unsignedBigInteger("supplier_id");
            $table->primary(["tenant_id", "supplier_id"]);
            $table->foreign("tenant_id")->references("id")->on("tenants")->onDelete("cascade");
            $table->foreign("supplier_id")->references("id")->on("suppliers")->onDelete("cascade");
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists("tenant_supplier");
    }
};
