<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/**
 * Supplier Core — Fase 3 / M1.1 — Tabela canonica de tenants.
 *
 * Cada whitelabel do legado (empresas.id) vira um tenant aqui, com slug estavel.
 * O master Goolhub (id_empresa=1) e o tenant "hubai" — default pros pedidos
 * que nao sao de whitelabel.
 *
 * Ver: Obsidian HubAI/Projetos/Supplier Core - Fase 3 Plano Executavel.md
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::create('tenants', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('slug', 64)->unique();
            $table->string('name');
            $table->unsignedInteger('legacy_empresa_id')->nullable()->unique();
            $table->string('status', 32)->default('active')->index();
            $table->string('default_supplier_visibility', 32)->default('all');
            $table->timestamps();
        });

        $now = now();
        $rows = [
            ['hubai',        'HubAI',         1,  'active'],
            ['alexdesign',   'Alex Design',   2,  'archived'],
            ['dropehub',     'DROPEHUB',      4,  'active'],
            ['envionacional','Envio Nacional', 5,  'active'],
            ['soudropsp',    'Sou Drop SP',   7,  'archived'],
            ['atravessador', 'Atravessador',  13, 'archived'],
            ['pluglar',      'PlugLar',       15, 'active'],
            ['plusdrop',     'PlusDrop',      16, 'active'],
            ['jtdrop',       'JTDrop',        17, 'active'],
            ['updrop',       'Updrop',        18, 'active'],
            ['dropmaxi',     'Dropmaxi',      19, 'active'],
            ['mestoredrop',  'MEStoreDrop',   20, 'active'],
            ['dropksr',      'DropKsr',       21, 'active'],
            ['weedrop',      'Weedrop',       22, 'active'],
            ['drop2you',     'Drop2You',      23, 'active'],
            ['multdrop',     'MultDrop',      24, 'active'],
        ];

        foreach ($rows as [$slug, $name, $legacyId, $status]) {
            DB::table('tenants')->insert([
                'id'                          => Str::uuid()->toString(),
                'slug'                        => $slug,
                'name'                        => $name,
                'legacy_empresa_id'           => $legacyId,
                'status'                      => $status,
                'default_supplier_visibility' => 'all',
                'created_at'                  => $now,
                'updated_at'                  => $now,
            ]);
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('tenants');
    }
};
