<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('drop_pricing_rules', function (Blueprint $table) {
            $table->id();
            $table->foreignId('client_id')->constrained('clients')->onDelete('cascade');
            $table->string('rule_name')->default('Padrao');
            $table->enum('rule_type', ['global', 'by_supplier', 'by_category'])->default('global');
            $table->string('supplier_slug')->nullable();
            $table->string('category_slug')->nullable();
            $table->decimal('markup_pct', 5, 2)->default(40.00);
            $table->decimal('min_margin_usd', 10, 4)->default(2.0000);
            $table->decimal('max_price_local', 10, 4)->nullable();
            $table->decimal('gateway_fee_pct', 5, 2)->default(3.50);
            $table->decimal('platform_fee_pct', 5, 2)->default(5.00);
            $table->boolean('include_shipping_in_price')->default(true);
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->index('client_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('drop_pricing_rules');
    }
};
