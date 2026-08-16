<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Tabela fiscal_notes
        Schema::create('fiscal_notes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('order_id')->constrained('orders')->onDelete('cascade');
            $table->unsignedBigInteger('supplier_id')->nullable()->index();
            $table->unsignedBigInteger('client_id')->nullable()->index();
            $table->enum('source', ['bling_supplier', 'bling_seller', 'mercadolivre', 'shopee'])->index();
            $table->string('nf_key', 44)->nullable()->index();
            $table->string('nf_number', 20)->nullable();
            $table->string('nf_series', 5)->nullable();
            $table->enum('status', ['pending', 'issued', 'cancelled', 'error'])->default('pending')->index();
            $table->timestamp('issued_at')->nullable();
            $table->decimal('value', 10, 2)->nullable();
            $table->string('xml_url', 500)->nullable();
            $table->string('pdf_url', 500)->nullable();
            $table->string('external_id', 100)->nullable()->index();
            $table->json('raw_data')->nullable();
            $table->timestamps();

            // Índice composto para idempotência
            $table->unique(['order_id', 'source'], 'fiscal_notes_order_source_unique');
        });

        // Colunas em marketplace_accounts (se não existirem)
        Schema::table('marketplace_accounts', function (Blueprint $table) {
            if (!Schema::hasColumn('marketplace_accounts', 'auto_invoice_enabled')) {
                $table->tinyInteger('auto_invoice_enabled')->default(0)->after('status');
            }
            if (!Schema::hasColumn('marketplace_accounts', 'invoice_series')) {
                $table->string('invoice_series', 5)->nullable()->default('1')->after('auto_invoice_enabled');
            }
        });

        // Colunas em orders (nf_status e nf_key)
        Schema::table('orders', function (Blueprint $table) {
            if (!Schema::hasColumn('orders', 'nf_status')) {
                $table->string('nf_status', 20)->nullable()->default(null)->after('invoice_url');
            }
            if (!Schema::hasColumn('orders', 'nf_key')) {
                $table->string('nf_key', 44)->nullable()->default(null)->after('nf_status');
            }
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('fiscal_notes');

        Schema::table('marketplace_accounts', function (Blueprint $table) {
            $table->dropColumnIfExists('auto_invoice_enabled');
            $table->dropColumnIfExists('invoice_series');
        });

        Schema::table('orders', function (Blueprint $table) {
            $table->dropColumnIfExists('nf_status');
            $table->dropColumnIfExists('nf_key');
        });
    }
};
