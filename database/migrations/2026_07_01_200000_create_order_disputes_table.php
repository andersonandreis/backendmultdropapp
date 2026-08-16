<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create("order_disputes", function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger("order_id");
            $table->foreign("order_id")->references("id")->on("orders")->onDelete("cascade");
            // Status da disputa (interna MultDrop/fornecedor)
            $table->enum("status", ["open", "in_review", "resolved", "rejected"])->default("open");
            // Motivo e descricao do lojista
            $table->string("reason", 200);
            $table->text("description")->nullable();
            // Paths dos arquivos de NF (armazenados localmente)
            $table->string("invoice_xml_path", 500)->nullable();
            $table->string("invoice_pdf_path", 500)->nullable();
            // URL publica para preview (gerada na leitura)
            $table->string("invoice_xml_url", 500)->nullable();
            $table->string("invoice_pdf_url", 500)->nullable();
            // Resolucao pelo admin MultDrop
            $table->text("resolution_notes")->nullable();
            // ID do usuario que abriu a disputa
            $table->unsignedBigInteger("opened_by_user_id")->nullable();
            // ID do admin que resolveu
            $table->unsignedBigInteger("resolved_by_user_id")->nullable();
            $table->timestamp("resolved_at")->nullable();
            $table->timestamps();

            $table->index(["order_id", "status"]);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists("order_disputes");
    }
};
