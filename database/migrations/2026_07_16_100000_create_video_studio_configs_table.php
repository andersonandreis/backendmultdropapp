<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;
return new class extends Migration {
    public function up(): void {
        Schema::create('video_studio_configs', function (Blueprint $t) {
            $t->id();
            $t->string('engine_id', 50)->unique(); // sg_fast, sg_balanced, sg_master, sg_realism
            $t->string('label', 100);
            $t->text('description')->nullable();
            $t->boolean('is_active')->default(true);
            $t->decimal('cost_cents', 10, 2)->default(0); // custo por geração (usado internamente)
            $t->decimal('client_price_cents', 10, 2)->default(0); // preço mostrado ao cliente
            $t->integer('display_order')->default(0);
            $t->timestamps();
        });
        DB::table('video_studio_configs')->insert([
            ['engine_id'=>'sg_fast','label'=>'Rápido','description'=>'Vídeo pronto em ~1min · ótimo pra teste rápido','is_active'=>1,'cost_cents'=>200,'client_price_cents'=>400,'display_order'=>1,'created_at'=>now(),'updated_at'=>now()],
            ['engine_id'=>'sg_balanced','label'=>'Recomendado','description'=>'Equilíbrio realismo × velocidade · padrão','is_active'=>1,'cost_cents'=>400,'client_price_cents'=>800,'display_order'=>2,'created_at'=>now(),'updated_at'=>now()],
            ['engine_id'=>'sg_master','label'=>'Qualidade máxima','description'=>'Cenas de referência — mais lento e mais detalhado','is_active'=>1,'cost_cents'=>1000,'client_price_cents'=>2000,'display_order'=>3,'created_at'=>now(),'updated_at'=>now()],
            ['engine_id'=>'sg_realism','label'=>'Realismo extremo','description'=>'Modelo mais recente · poros, textura, movimento','is_active'=>1,'cost_cents'=>1500,'client_price_cents'=>3000,'display_order'=>4,'created_at'=>now(),'updated_at'=>now()],
        ]);
    }
    public function down(): void { Schema::dropIfExists('video_studio_configs'); }
};
