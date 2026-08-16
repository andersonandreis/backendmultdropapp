<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Campos adicionais exigidos por Mercado Livre e Shopee.
     * Campos já existentes na tabela products (não duplicados):
     *   gtin, brand, model, weight_kg, height_cm, width_cm, length_cm,
     *   condition, attributes, warranty_type, warranty_days.
     *
     * Adicionados aqui:
     *   - ean         → alias EAN-13 separado do GTIN (ML aceita ambos)
     *   - model_name  → nome comercial do modelo (model já existe, model_name é campo publicitário)
     *   - warranty_months → garantia em meses para ML/Shopee (warranty_days já existe p/ uso interno)
     *   - video_url   → URL YouTube para ML (Shopee exige upload próprio via API deles)
     */
    public function up(): void
    {
        Schema::table('products', function (Blueprint $table) {
            if (! Schema::hasColumn('products', 'ean')) {
                $table->string('ean', 20)->nullable()->after('gtin');
            }
            if (! Schema::hasColumn('products', 'model_name')) {
                $table->string('model_name', 100)->nullable()->after('model');
            }
            if (! Schema::hasColumn('products', 'warranty_months')) {
                $table->integer('warranty_months')->nullable()->after('warranty_days');
            }
            if (! Schema::hasColumn('products', 'video_url')) {
                $table->string('video_url', 500)->nullable()->after('attributes');
            }
        });
    }

    public function down(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->dropColumn(array_filter([
                Schema::hasColumn('products', 'ean') ? 'ean' : null,
                Schema::hasColumn('products', 'model_name') ? 'model_name' : null,
                Schema::hasColumn('products', 'warranty_months') ? 'warranty_months' : null,
                Schema::hasColumn('products', 'video_url') ? 'video_url' : null,
            ]));
        });
    }
};
