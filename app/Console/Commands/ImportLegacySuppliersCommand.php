<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use App\Models\Supplier;

/**
 * Importa fornecedores ativos do legado (tabela deposito) para o novo sistema.
 *
 * Cada deposito com liberada=1 vira um Supplier caso ainda nao exista
 * (verificado via legacy_id). Nao sobrescreve dados de suppliers ja existentes.
 *
 * NOV-067 - 2026-06-24
 */
class ImportLegacySuppliersCommand extends Command
{
    protected $signature = 'hubai:import-legacy-suppliers
                            {--dry-run : Mostrar o que seria criado sem salvar}
                            {--force : Atualizar campos de suppliers ja existentes}';

    protected $description = 'Importa todos os depositos ativos do legado como Suppliers no novo sistema';

    /**
     * user_id do admin - todos os suppliers importados ficam vinculados ao admin
     * ate que o fornecedor faca login e assuma a conta.
     */
    private const ADMIN_USER_ID = 1;

    public function handle(): int
    {
        $isDryRun = $this->option('dry-run');
        $isForce  = $this->option('force');

        if ($isDryRun) {
            $this->warn('[DRY-RUN] Nenhum dado sera salvo.');
        }

        // 1. Buscar todos os depositos ativos no legado
        $depositos = DB::connection('legacy')
            ->table('deposito')
            ->where('liberada', 1)
            ->orderBy('id')
            ->get(['id', 'descricao', 'email', 'telefone', 'cidade', 'estado',
                   'logradouro', 'cep', 'taxa_pix', 'pagamento_direto',
                   'fabrica', 'meli_flex_ativado']);

        $this->info("Depositos ativos no legado: {$depositos->count()}");
        $this->newLine();

        $created  = 0;
        $existing = 0;
        $updated  = 0;

        foreach ($depositos as $dep) {
            $supplier = Supplier::where('legacy_id', $dep->id)->first();

            if ($supplier && !$isForce) {
                $this->line(sprintf(
                    'JA EXISTE  - dep.id=%d | %s -> supplier.id=%d (slug=%s)',
                    $dep->id,
                    $dep->descricao,
                    $supplier->id,
                    $supplier->slug
                ));
                $existing++;
                continue;
            }

            // Gerar slug unico baseado no nome do deposito
            $baseSlug = Str::slug($dep->descricao ?: 'deposito-' . $dep->id);
            $slug     = $this->uniqueSlug($baseSlug, $supplier?->id);

            $data = [
                'legacy_id'             => $dep->id,
                'user_id'               => self::ADMIN_USER_ID,
                'document'              => '00000000000000',
                'type'                  => 'warehouse',
                'company_name'          => trim($dep->descricao ?? 'Deposito ' . $dep->id),
                'display_name'          => trim($dep->descricao ?? 'Deposito ' . $dep->id),
                'phone'                 => $dep->telefone ?? null,
                'city'                  => $dep->cidade ?? null,
                'state'                 => $dep->estado ?? null,
                'address'               => $dep->logradouro ?? null,
                'zipcode'               => $dep->cep ?? null,
                'pix_fee'               => $dep->taxa_pix ?? 0,
                'allows_direct_payment' => (bool) ($dep->pagamento_direto ?? false),
                'is_factory'            => (bool) ($dep->fabrica ?? false),
                'supports_meli_flex'    => (bool) ($dep->meli_flex_ativado ?? false),
                'allows_direct_deposit' => true,
                'is_active'             => true,
                'is_private'            => false,
            ];

            $action = ($supplier && $isForce) ? 'ATUALIZAR' : 'CRIAR';

            $this->line(sprintf(
                '%s - dep.id=%d | %s | cidade=%s/%s',
                $action,
                $dep->id,
                $data['company_name'],
                $data['city'] ?? '-',
                $data['state'] ?? '-'
            ));

            if (!$isDryRun) {
                if ($supplier && $isForce) {
                    $supplier->update($data);
                    $updated++;
                } else {
                    // Adicionar slug apenas na criacao (HasSlug trait pode gerar automaticamente)
                    $data['slug'] = $slug;
                    Supplier::create($data);
                    $created++;
                }
            } else {
                if ($supplier) {
                    $updated++;
                } else {
                    $created++;
                }
            }
        }

        $this->newLine();
        $this->info('--- Resultado ---');

        if ($isDryRun) {
            $this->info("Seriam criados: {$created} | Seriam atualizados: {$updated} | Ja existiam (pulados): {$existing}");
        } else {
            $this->info("Criados: {$created} | Atualizados: {$updated} | Ja existiam (pulados): {$existing}");
        }

        return self::SUCCESS;
    }

    /**
     * Gera um slug unico, adicionando sufixo numerico se necessario.
     * Exclui o supplier atual (para o caso de --force update).
     */
    private function uniqueSlug(string $base, ?int $excludeId = null): string
    {
        $slug    = $base;
        $counter = 2;

        while (true) {
            $query = Supplier::where('slug', $slug);
            if ($excludeId) {
                $query->where('id', '!=', $excludeId);
            }

            if (!$query->exists()) {
                return $slug;
            }

            $slug = $base . '-' . $counter;
            $counter++;
        }
    }
}
