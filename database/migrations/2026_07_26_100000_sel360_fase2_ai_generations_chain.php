<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * SEL-360 Fase 2 — Chain de geração (DAG):
 *   parent_generation_id: nó pai no DAG (NULL = raiz)
 *   step_role: papel deste nó no chain (intermediate_image | voice | final_video | edit_pass)
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('ai_generations', function (Blueprint $table) {
            // Só adiciona se não existir (segurança em re-run)
            if (!Schema::hasColumn('ai_generations', 'parent_generation_id')) {
                $table->unsignedBigInteger('parent_generation_id')->nullable()->after('id');
                $table->index('parent_generation_id', 'ai_gen_parent_idx');
            }
            if (!Schema::hasColumn('ai_generations', 'step_role')) {
                $table->string('step_role', 32)->nullable()->after('parent_generation_id')
                    ->comment('intermediate_image|voice|final_video|edit_pass');
            }
        });
    }

    public function down(): void
    {
        Schema::table('ai_generations', function (Blueprint $table) {
            $table->dropIndexIfExists('ai_gen_parent_idx');
            $table->dropColumnIfExists('parent_generation_id');
            $table->dropColumnIfExists('step_role');
        });
    }
};
