<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('product_bundles', function (Blueprint $table) {
            $table->id();
            $table->foreignId('supplier_id')->constrained('suppliers')->cascadeOnDelete();
            $table->foreignId('parent_product_id')->constrained('products')->cascadeOnDelete();
            $table->foreignId('component_product_id')->constrained('products')->cascadeOnDelete();
            $table->unsignedInteger('qty')->default(1);
            $table->timestamps();
            $table->unique(['parent_product_id', 'component_product_id'], 'unique_bundle_component');
            $table->index('supplier_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('product_bundles');
    }
};
