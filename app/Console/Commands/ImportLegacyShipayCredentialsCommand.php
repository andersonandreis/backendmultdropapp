<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Models\Supplier;
use App\Models\SupplierPaymentSetting;
use Illuminate\Support\Facades\DB;

class ImportLegacyShipayCredentialsCommand extends Command
{
    protected $signature = 'hubai:import-legacy-shipay-credentials
                            {--dry-run : Mostrar o que seria importado sem salvar}
                            {--force : Sobrescrever registros existentes}';

    protected $description = 'Importa credenciais Shipay dos fornecedores do sistema legado (token_shipay)';

    public function handle(): int
    {
        $isDryRun = $this->option('dry-run');
        $isForce  = $this->option('force');

        if ($isDryRun) {
            $this->warn('[DRY-RUN] Nenhum dado sera salvo.');
        }

        $legacyTokens = DB::connection('legacy')
            ->table('token_shipay')
            ->where('status', 1)
            ->whereNotNull('client_id')
            ->where('client_id', '!=', '')
            ->orderBy('id')
            ->get();

        $this->info("Tokens Shipay ativos com client_id no legado: {$legacyTokens->count()}");
        $this->newLine();

        $imported = 0;
        $skipped  = 0;
        $noMatch  = 0;

        foreach ($legacyTokens as $token) {
            $supplier = null;

            if (!empty($token->id_empresa)) {
                $supplier = Supplier::where('legacy_empresa_id', $token->id_empresa)->first();
            }

            if (!$supplier && !empty($token->id_deposito)) {
                $supplier = Supplier::where('legacy_id', $token->id_deposito)->first();
            }

            if (!$supplier) {
                $this->warn(sprintf(
                    'SEM MATCH - token_shipay.id=%d | id_empresa=%s | id_deposito=%s | obs=%s',
                    $token->id,
                    $token->id_empresa ?? 'NULL',
                    $token->id_deposito ?? 'NULL',
                    $token->obs ?? '-'
                ));
                $noMatch++;
                continue;
            }

            $existing = SupplierPaymentSetting::where('supplier_id', $supplier->id)
                ->where('gateway', 'shipay')
                ->first();

            if ($existing && !$isForce) {
                $this->line(sprintf(
                    'JA EXISTE - %s (supplier_id=%d) -> Shipay ja configurado. Use --force para sobrescrever.',
                    $supplier->company_name,
                    $supplier->id
                ));
                $skipped++;
                continue;
            }

            $accessKey = $token->access_key ?? null;

            $this->line(sprintf(
                '%s - %s (supplier_id=%d) | legado: id_empresa=%s id_deposito=%s | obs=%s',
                $existing ? 'ATUALIZAR' : 'IMPORTAR',
                $supplier->company_name,
                $supplier->id,
                $token->id_empresa ?? 'NULL',
                $token->id_deposito ?? 'NULL',
                $token->obs ?? '-'
            ));

            if (!$isDryRun) {
                $data = [
                    'gateway'      => 'shipay',
                    'api_key'      => $token->client_id,
                    'api_secret'   => $token->secret_key,
                    'api_extra'    => $accessKey ? ['access_key' => $accessKey] : null,
                    'is_active'    => true,
                ];

                if ($existing) {
                    $existing->update($data);
                } else {
                    SupplierPaymentSetting::create(array_merge($data, [
                        'supplier_id' => $supplier->id,
                    ]));
                }
            }
            $imported++;
        }

        $this->newLine();
        $this->info('--- Resultado ---');
        $this->info($isDryRun
            ? "Seriam importados/atualizados: $imported | Ja existiam (pulados): $skipped | Sem match: $noMatch"
            : "Importados/atualizados: $imported | Pulados: $skipped | Sem match: $noMatch"
        );

        if ($noMatch > 0) {
            $this->warn("$noMatch token(s) sem fornecedor correspondente no novo sistema - revisar manualmente.");
        }

        return self::SUCCESS;
    }
}
