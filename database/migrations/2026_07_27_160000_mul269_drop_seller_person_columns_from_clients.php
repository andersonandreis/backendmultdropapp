<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * MUL-269 fase 2 — dropa as colunas de dados pessoais e o antigo
 * `company_name` da tabela `clients` (multdrop). Todo acesso ao "nome
 * do seller" passou a puxar de `users` (accessor no model Client +
 * COALESCE(NULLIF(users.full_name,''), users.name) em SQL puro).
 *
 * Guarded por hasTable/hasColumn — repositorio compartilhado por 7
 * backends, so multdrop tem as colunas depois de rodar essa migration.
 *
 * NAO RODAR direto: espera OK do Ruan na planilha; sessao principal
 * roda com `--path` apenas no multdrop.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('clients')) {
            return;
        }

        $cols = [
            'company_name',
            'full_name',
            'responsible_cpf',
            'birth_date',
            'rg',
            'mother_name',
            'father_name',
            'rg_front_file',
            'rg_back_file',
            'residence_proof_file',
        ];

        $existing = array_values(array_filter($cols, fn ($c) => Schema::hasColumn('clients', $c)));
        if (empty($existing)) {
            return;
        }

        Schema::table('clients', function (Blueprint $table) use ($existing) {
            $table->dropColumn($existing);
        });
    }

    public function down(): void
    {
        // MUL-269 fase 2: recriacao best-effort das colunas dropadas em `up`.
        // Nao restaura dados — apenas o schema — pra permitir rollback tecnico.
        if (!Schema::hasTable('clients')) {
            return;
        }

        Schema::table('clients', function (Blueprint $table) {
            if (!Schema::hasColumn('clients', 'company_name')) {
                $table->string('company_name', 255)->nullable();
            }
            if (!Schema::hasColumn('clients', 'full_name')) {
                $table->string('full_name', 255)->nullable();
            }
            if (!Schema::hasColumn('clients', 'responsible_cpf')) {
                $table->string('responsible_cpf', 20)->nullable();
            }
            if (!Schema::hasColumn('clients', 'birth_date')) {
                $table->date('birth_date')->nullable();
            }
            if (!Schema::hasColumn('clients', 'rg')) {
                $table->string('rg', 30)->nullable();
            }
            if (!Schema::hasColumn('clients', 'mother_name')) {
                $table->string('mother_name', 255)->nullable();
            }
            if (!Schema::hasColumn('clients', 'father_name')) {
                $table->string('father_name', 255)->nullable();
            }
            if (!Schema::hasColumn('clients', 'rg_front_file')) {
                $table->string('rg_front_file', 500)->nullable();
            }
            if (!Schema::hasColumn('clients', 'rg_back_file')) {
                $table->string('rg_back_file', 500)->nullable();
            }
            if (!Schema::hasColumn('clients', 'residence_proof_file')) {
                $table->string('residence_proof_file', 500)->nullable();
            }
        });
    }
};
