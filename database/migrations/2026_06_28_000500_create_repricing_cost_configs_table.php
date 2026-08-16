<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('repricing_cost_configs', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('supplier_id')->index();
            $t->string('marketplace', 30)->index();
            $t->string('product_category', 100)->nullable()->index();
            $t->decimal('shipping_cost_pct', 6, 3)->default(0)->comment('% sobre venda — custo médio frete');
            $t->decimal('marketplace_fee_pct', 6, 3)->default(0)->comment('% taxa marketplace');
            $t->decimal('desired_margin_pct', 6, 3)->default(20)->comment('% margem alvo');
            $t->decimal('extra_cost_fixed', 10, 2)->default(0)->comment('R$ fixo por venda');
            $t->boolean('active')->default(true);
            $t->timestamps();
            $t->unique(['supplier_id', 'marketplace', 'product_category'], 'rcc_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('repricing_cost_configs');
    }
};
