<?php

namespace App\Console\Commands;

use App\Models\DirectorySupplier;
use Illuminate\Console\Command;
use Illuminate\Support\Str;

/**
 * SEL-048: Importa/atualiza o diretorio de fornecedores a partir de um JSON.
 *
 * Uso:
 *   php artisan directory:import-suppliers /root/sel-048-kiwify/fornecedores-final.json
 *   php artisan directory:import-suppliers /root/sel-048-kiwify/fornecedores-final.json --dry-run
 *
 * O JSON e um array de objetos com o schema definido em sel-048-kiwify/schema-fornecedor.md.
 * O upsert e feito pelo slug (campo unico). Se o registro existir, atualiza.
 * Se nao existir, cria.
 *
 * Mapeamento de campos (JSON -> DB):
 *   nome              -> name
 *   categorias[]      -> categories (array JSON; fallback: [categoria])
 *   descricao         -> description
 *   localizacao       -> location  (truncado em 255 chars)
 *   email             -> email
 *   telefone          -> phone
 *   whatsapp          -> whatsapp
 *   instagram         -> instagram
 *   outras_redes      -> other_socials
 *   site              -> site
 *   catalogo_url      -> catalog_url
 *   marketplaces[]    -> marketplaces
 *   pedido_minimo     -> min_order
 *   formas_envio      -> shipping_info
 *   condicoes_comerciais -> commercial_terms
 *   verificado        -> verified
 *   fontes[]|fonte_pdf -> sources
 *   observacoes       -> notes
 */
class ImportDirectorySuppliersCommand extends Command
{
    protected $signature = 'directory:import-suppliers
                            {path : Caminho absoluto para o arquivo JSON}
                            {--dry-run : Apenas conta e valida, nao persiste}';

    protected $description = 'SEL-048: Importa/atualiza diretorio de fornecedores via upsert por slug';

    public function handle(): int
    {
        $path = $this->argument('path');
        $dryRun = $this->option('dry-run');

        if (!file_exists($path)) {
            $this->error("Arquivo nao encontrado: {$path}");
            return self::FAILURE;
        }

        $this->info("Lendo {$path}...");
        $raw = file_get_contents($path);
        $records = json_decode($raw, true);

        if (!is_array($records)) {
            $this->error('JSON invalido ou nao e um array.');
            return self::FAILURE;
        }

        $total   = count($records);
        $created = 0;
        $updated = 0;
        $skipped = 0;
        $errors  = [];

        $this->info("Total de registros no JSON: {$total}");
        $this->output->progressStart($total);

        foreach ($records as $index => $row) {
            try {
                $slug = $this->resolveSlug($row, $index);
                $data = $this->mapRow($row, $slug);

                if ($dryRun) {
                    $skipped++;
                    $this->output->progressAdvance();
                    continue;
                }

                $existing = DirectorySupplier::where('slug', $slug)->first();

                if ($existing) {
                    $existing->update($data);
                    $updated++;
                } else {
                    DirectorySupplier::create($data);
                    $created++;
                }
            } catch (\Throwable $e) {
                $name = $row['nome'] ?? "index_{$index}";
                $errors[] = "[{$name}] " . $e->getMessage();
                $skipped++;
            }

            $this->output->progressAdvance();
        }

        $this->output->progressFinish();

        $this->newLine();
        $this->info("Resultado:");
        $this->line("  Total no JSON : {$total}");

        if ($dryRun) {
            $this->warn("  [DRY-RUN] Nenhuma alteracao persistida.");
        } else {
            $this->line("  Criados       : {$created}");
            $this->line("  Atualizados   : {$updated}");
            $this->line("  Ignorados/err : {$skipped}");

            // Contagem final no banco
            $dbCount = DirectorySupplier::count();
            $this->info("  Total no banco: {$dbCount}");
        }

        $errCount = count($errors);
        if ($errCount > 0) {
            $this->warn("\nErros ({$errCount}):");
            foreach (array_slice($errors, 0, 10) as $err) {
                $this->warn("  - {$err}");
            }
            if ($errCount > 10) {
                $this->warn("  ... e mais " . ($errCount - 10) . " erros.");
            }
        }

        return self::SUCCESS;
    }

    // --------------------------------------------------------------- Helpers

    private function resolveSlug(array $row, int $index): string
    {
        // Usa o slug pre-gerado no JSON se existir
        if (!empty($row['slug'])) {
            return $row['slug'];
        }

        // Gera a partir do nome
        if (!empty($row['nome'])) {
            return Str::slug($row['nome']) ?: 'fornecedor-' . ($index + 1);
        }

        return 'fornecedor-' . ($index + 1);
    }

    /**
     * Mapeia um registro do JSON para os campos do banco.
     */
    private function mapRow(array $row, string $slug): array
    {
        // Categorias: preferir campo 'categorias' (array); fallback 'categoria' (string)
        $categories = [];
        if (!empty($row['categorias']) && is_array($row['categorias'])) {
            $categories = $row['categorias'];
        } elseif (!empty($row['categoria'])) {
            $categories = [$row['categoria']];
        }

        // Fontes: preferir 'fontes' (array); fallback 'fonte_pdf' (string)
        $sources = [];
        if (!empty($row['fontes']) && is_array($row['fontes'])) {
            $sources = $row['fontes'];
        } elseif (!empty($row['fonte_pdf'])) {
            $sources = [$row['fonte_pdf']];
        }

        // Marketplaces: garantir array
        $marketplaces = [];
        if (!empty($row['marketplaces']) && is_array($row['marketplaces'])) {
            $marketplaces = $row['marketplaces'];
        } elseif (!empty($row['marketplaces']) && is_string($row['marketplaces'])) {
            $marketplaces = [$row['marketplaces']];
        }

        // Outras redes: garantir array/objeto
        $otherSocials = null;
        if (!empty($row['outras_redes'])) {
            $otherSocials = is_array($row['outras_redes']) ? $row['outras_redes'] : null;
        }

        return [
            'name'             => $row['nome'] ?? 'Sem nome',
            'slug'             => $slug,
            'categories'       => !empty($categories) ? $categories : null,
            'description'      => $row['descricao'] ?? null,
            'location'         => $this->truncate($this->nullIfEmpty($row['localizacao'] ?? null), 255),
            'email'            => $this->nullIfEmpty($row['email'] ?? null),
            'phone'            => $this->nullIfEmpty($row['telefone'] ?? null),
            'whatsapp'         => $this->nullIfEmpty($row['whatsapp'] ?? null),
            'instagram'        => $this->nullIfEmpty($row['instagram'] ?? null),
            'other_socials'    => $otherSocials,
            'site'             => $this->nullIfEmpty($row['site'] ?? null),
            'catalog_url'      => $this->nullIfEmpty($row['catalogo_url'] ?? null),
            'marketplaces'     => !empty($marketplaces) ? $marketplaces : null,
            'min_order'        => $this->nullIfEmpty($row['pedido_minimo'] ?? null),
            'shipping_info'    => $this->nullIfEmpty($row['formas_envio'] ?? null),
            'commercial_terms' => $this->nullIfEmpty($row['condicoes_comerciais'] ?? null),
            'verified'         => (bool) ($row['verificado'] ?? false),
            'sources'          => !empty($sources) ? $sources : null,
            'notes'            => $this->nullIfEmpty($row['observacoes'] ?? null),
            'min_plan_id'      => null, // liberado para todos por enquanto
            'is_active'        => true,
        ];
    }

    private function truncate(?string $value, int $length): ?string
    {
        if ($value === null) {
            return null;
        }
        return mb_strlen($value) > $length ? mb_substr($value, 0, $length) : $value;
    }

    private function nullIfEmpty(mixed $value): mixed
    {
        if ($value === null) {
            return null;
        }
        $str = trim((string) $value);
        return $str === '' ? null : $value;
    }
}
