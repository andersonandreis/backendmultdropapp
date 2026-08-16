<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('client_products', function (Blueprint $table) {
            $table->string('custom_brand')->nullable()->after('custom_attributes');
            $table->string('custom_model')->nullable()->after('custom_brand');
            $table->string('custom_gtin')->nullable()->after('custom_model');
            $table->string('custom_condition')->nullable()->after('custom_gtin');
            $table->string('custom_warranty_type')->nullable()->after('custom_condition');
            $table->integer('custom_warranty_days')->nullable()->after('custom_warranty_type');
            $table->decimal('custom_weight_kg', 8, 3)->nullable()->after('custom_warranty_days');
            $table->decimal('custom_height_cm', 8, 2)->nullable()->after('custom_weight_kg');
            $table->decimal('custom_width_cm', 8, 2)->nullable()->after('custom_height_cm');
            $table->decimal('custom_length_cm', 8, 2)->nullable()->after('custom_width_cm');
            $table->json('custom_images')->nullable()->after('custom_length_cm');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('client_products', function (Blueprint $table) {
            $table->dropColumn([
                'custom_brand',
                'custom_model',
                'custom_gtin',
                'custom_condition',
                'custom_warranty_type',
                'custom_warranty_days',
                'custom_weight_kg',
                'custom_height_cm',
                'custom_width_cm',
                'custom_length_cm',
                'custom_images'
            ]);
        });
    }
};
