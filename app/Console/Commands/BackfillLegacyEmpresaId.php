<?php

namespace App\Console\Commands;

use App\Models\Supplier;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class BackfillLegacyEmpresaId extends Command
{
    protected $signature = 'backfill:legacy-empresa-id
                            {--dry-run : Simulate without persisting changes}
                            {--force-all : Re-process suppliers that already have legacy_empresa_id}
                            {--no-create-missing : Skip INSERT for unmatched suppliers (leave legacy_empresa_id null)}';

    protected $description = 'Backfill suppliers.legacy_empresa_id by matching company_name against legacy deposito.menu_titulo. Creates a new deposito entry for unmatched suppliers by default.';

    public function handle(): int
    {

        // -----------------------------------------------------------------
        // PINNED mappings: supplier_id => legacy_empresa_id
        // These pairs are canonical and must NEVER be overwritten by
        // fuzzy/backfill logic. DropRio=JTDrop(53), PlugLar(61), MultDrop(498)
        // Updated: 2026-05-17
        // -----------------------------------------------------------------
        $pinnedMappings = [
            1  => 24,   // MultDrop -> EMPRESA id=24 (app.multdropbr.com) [MUL-055 2026-06-26: era 498 (DEPOSITO), corrigido pra EMPRESA. Vide MUL-052/055]
            2  => 61,   // JTDrop   -> deposito id=61
        ];

        // Enforce pinned mappings unconditionally before any other processing
        foreach ($pinnedMappings as $supplierId => $legacyId) {
            Supplier::where('id', $supplierId)->update(['legacy_empresa_id' => $legacyId]);
        }

        $dryRun        = $this->option('dry-run');
        $forceAll      = $this->option('force-all');
        $createMissing = ! $this->option('no-create-missing'); // default: true

        $query = Supplier::query();

        if (! $forceAll) {
            $query->whereNull('legacy_empresa_id');
        }

        $total = $query->count();

        if ($total === 0) {
            $this->info('No suppliers to process.');
            return self::SUCCESS;
        }

        $this->info(sprintf(
            'Processing %d supplier(s)%s%s...',
            $total,
            $dryRun        ? ' [DRY-RUN]'          : '',
            $createMissing ? ' [CREATE-MISSING=ON]' : ' [CREATE-MISSING=OFF]'
        ));

        // Pre-load all depositos into memory (all statuses, to support reuse of inactive ones too)
        $depositos = DB::connection('legacy')
            ->table('deposito')
            ->get(['id', 'menu_titulo'])
            ->map(fn ($d) => [
                'id'          => $d->id,
                'menu_titulo' => $d->menu_titulo,
                'normalized'  => $this->normalize($d->menu_titulo ?? ''),
            ])
            ->filter(fn ($d) => $d['normalized'] !== '')
            ->values();

        $matchedExact = 0;
        $matchedFuzzy = 0;
        $created      = 0;
        $unmatched    = 0;

        $query->chunkById(50, function ($suppliers) use (
            $dryRun,
            $createMissing,
            $pinnedMappings,
            &$depositos,
            &$matchedExact,
            &$matchedFuzzy,
            &$created,
            &$unmatched
        ) {
            foreach ($suppliers as $supplier) {
                // Skip pinned suppliers - their mapping is canonical.
                if (array_key_exists($supplier->id, $pinnedMappings)) {
                    $this->line(sprintf(
                        '  [PINNED] supplier_id=%d "%s" -> deposito_id=%d (protected, skipped)',
                        $supplier->id,
                        $supplier->company_name,
                        $pinnedMappings[$supplier->id]
                    ));
                    continue;
                }

                $foundId  = null;
                $method   = null;
                $nameNorm = $this->normalize($supplier->company_name ?? '');

                if ($nameNorm === '') {
                    $this->warn(sprintf(
                        '  [SKIP] supplier_id=%d has empty company_name',
                        $supplier->id
                    ));
                    $unmatched++;
                    continue;
                }

                // -- Attempt 1: exact normalized match -----------------------
                $hit = $depositos->firstWhere('normalized', $nameNorm);
                if ($hit) {
                    $foundId = $hit['id'];
                    $method  = 'EXACT';
                }

                // -- Attempt 2: partial / fuzzy (first word, min 4 chars) ---
                if (! $foundId) {
                    $words     = preg_split('/\s+/', $nameNorm);
                    $firstWord = $words[0] ?? '';

                    if (strlen($firstWord) >= 4) {
                        $hit = $depositos->first(
                            fn ($d) => str_contains($d['normalized'], $firstWord)
                        );

                        if ($hit) {
                            $foundId = $hit['id'];
                            $method  = 'FUZZY';
                        }
                    }
                }

                // -- Attempt 3: create new deposito (--create-missing, default ON) ---
                if (! $foundId && $createMissing) {
                    $name = trim($supplier->company_name);

                    // Idempotency: check exact menu_titulo before inserting
                    $existing = DB::connection('legacy')
                        ->table('deposito')
                        ->whereRaw('LOWER(TRIM(menu_titulo)) = ?', [mb_strtolower($name)])
                        ->orderBy('id', 'desc')
                        ->first(['id', 'menu_titulo']);

                    if ($existing) {
                        $foundId = $existing->id;
                        $method  = 'REUSED';
                    } else {
                        if (! $dryRun) {
                            $foundId = DB::connection('legacy')->table('deposito')->insertGetId([
                                'descricao'          => $name,
                                'menu_titulo'        => $name,
                                'liberada'           => 1,
                                'liberada_manual'    => 1,
                                'liberada_full'      => 1,
                                'tipo_pagamento'     => 1,
                                'cobrar'             => 1,
                                'id_empresa'         => 1,
                                'moeda'              => 'R$',
                                'pagamento_direto'   => 1,
                                'aceitar_integracao' => 1,
                                'telefone'           => $supplier->phone ?? null,
                                'estado'             => $supplier->state ?? null,
                                'cidade'             => $supplier->city  ?? null,
                            ]);

                            // Append to in-memory collection so subsequent chunks
                            // won't duplicate this deposito in the same run
                            $depositos->push([
                                'id'          => $foundId,
                                'menu_titulo' => $name,
                                'normalized'  => $this->normalize($name),
                            ]);
                        } else {
                            $foundId = '[NEW-DRY]';
                        }
                        $method = 'CREATED';
                    }
                }

                if ($foundId) {
                    $this->line(sprintf(
                        '  [%s] supplier_id=%d "%s" -> deposito_id=%s',
                        $method,
                        $supplier->id,
                        $supplier->company_name,
                        $foundId
                    ));

                    if (! $dryRun && $foundId !== '[NEW-DRY]') {
                        $supplier->update(['legacy_empresa_id' => $foundId]);
                    }

                    match ($method) {
                        'EXACT'   => $matchedExact++,
                        'FUZZY'   => $matchedFuzzy++,
                        'CREATED' => $created++,
                        'REUSED'  => $created++,
                        default   => null,
                    };
                } else {
                    $this->warn(sprintf(
                        '  [UNMATCHED] supplier_id=%d "%s"',
                        $supplier->id,
                        $supplier->company_name
                    ));
                    $unmatched++;
                }
            }
        });

        $this->newLine();
        $this->table(
            ['Metric', 'Count'],
            [
                ['Matched exact',           $matchedExact],
                ['Matched fuzzy',           $matchedFuzzy],
                ['Created/Reused deposito', $created],
                ['Unmatched (skipped)',     $unmatched],
                ['Total processed',         $matchedExact + $matchedFuzzy + $created + $unmatched],
            ]
        );

        if ($dryRun) {
            $this->warn('DRY-RUN: no changes were persisted.');
        }

        return self::SUCCESS;
    }

    /**
     * Normalize a string for comparison:
     * lowercase, trim, collapse multiple spaces, strip accents.
     */
    private function normalize(string $value): string
    {
        $value = mb_strtolower(trim($value));

        // Strip accents via transliteration
        $value = iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $value) ?: $value;

        // Collapse multiple spaces
        $value = preg_replace('/\s+/', ' ', $value);

        return trim($value);
    }
}
