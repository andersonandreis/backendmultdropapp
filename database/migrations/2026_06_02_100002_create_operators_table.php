<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Tabela de operadores de picking/packing.
 *
 * Cada operador possui um badge_code unico (cracha bipado via scanner)
 * para identificacao rapida nas estacoes de picking/packing.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('operators', function (Blueprint $table) {
            $table->id();
            $table->string('name', 100);
            $table->string('badge_code', 64)->unique();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('operators');
    }
};
