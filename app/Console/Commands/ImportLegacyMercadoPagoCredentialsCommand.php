<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Models\Supplier;
use App\Models\SupplierPaymentSetting;
use Illuminate\Support\Facades\DB;

class ImportLegacyMercadoPagoCredentialsCommand extends Command
{
    protected $signature = 'hubai:import-legacy-mp-credentials
                            {--dry-run : Mostrar o que seria importado sem salvar}
                            {--force : Sobrescrever gateway existente (ex: Shipay -> MercadoPago)}';

    protected $description = 'Importa credenciais Mercado Pago dos fornecedores do sistema legado (tokens_empresas_mp)';

    public function handle(): int
    {
        $isDryRun = $this->option('dry-run');
        $isForce  = $this->option('force');

        if ($isDryRun) {
            $this->warn('[DRY-RUN] Nenhum dado sera salvo.');
        }

        $legacyTokens = DB::connection('legacy')
            ->table('tokens_empresas_mp')
            ->where('ativo', 1)
            ->orderBy('id')
            ->get();

        $this->info("Tokens Mercado Pago ativos no legado: {$legacyTokens->count()}");
        $this->newLine();

        $imported          = 0;
        $skipped           = 0;
        $noMatch           = 0;
        $noCredentials     = 0;
        $processedSupplierIds = [];

        foreach ($legacyTokens as $token) {
            $supplier = null;

            if (!empty($token->id_deposito)) {
                $supplier = Supplier::where('legacy_id', $token->id_deposito)->first();
            }

            if (!$supplier && !empty($token->id_empresa)) {
                $supplier = Supplier::where('legacy_empresa_id', $token->id_empresa)->first();
            }

            if (!$supplier) {
                $this->warn(sprintf(
                    'SEM MATCH - tokens_empresas_mp.id=%d | id_empresa=%s | id_deposito=%s',
                    $token->id,
                    $token->id_empresa ?? 'NULL',
                    $token->id_deposito ?? 'NULL'
                ));
                $noMatch++;
                continue;
            }

            if (empty($token->access_token) || empty($token->public_key)) {
                $this->warn(sprintf(
                    'SEM CREDENCIAIS - %s (supplier_id=%d) | tokens_empresas_mp.id=%d',
                    $supplier->company_name,
                    $supplier->id,
                    $token->id
                ));
                $noCredentials++;
                continue;
            }

            if (in_array($supplier->id, $processedSupplierIds)) {
                $this->line(sprintf(
                    'DUPLICATA - %s (supplier_id=%d) ja processado, tokens_empresas_mp.id=%d ignorado',
                    $supplier->company_name,
                    $supplier->id,
                    $token->id
                ));
                $skipped++;
                continue;
            }

            $existing = SupplierPaymentSetting::where('supplier_id', $supplier->id)->first();

            if ($existing) {
                if (!$isForce) {
                    $this->warn(sprintf(
                        'GATEWAY EXISTENTE - %s (supplier_id=%d) ja usa [%s]. Use --force para substituir por mercadopago.',
                        $supplier->company_name,
                        $supplier->id,
                        $existing->gateway
                    ));
                    $skipped++;
                    continue;
                }
            }

            $action = !$existing ? 'IMPORTAR' : sprintf('ATUALIZAR (era %s)', $existing->gateway);

            $this->line(sprintf(
                '%s - %s (supplier_id=%d) | legado: id_empresa=%s id_deposito=%s | mp_id=%s',
                $action,
                $supplier->company_name,
                $supplier->id,
                $token->id_empresa ?? 'NULL',
                $token->id_deposito ?? 'NULL',
                $token->client_id ?? 'NULL'
            ));

            if (!$isDryRun) {
                $apiExtra = array_filter([
                    'client_id'     => $token->client_id ?: null,
                    'client_secret' => $token->client_secret ?: null,
                    'url_webhook'   => $token->url_webhook ?: null,
                ]);

                $data = [
                    'gateway'    => 'mercadopago',
                    'api_key'    => $token->public_key,
                    'api_secret' => $token->access_token,
                    'api_extra'  => !empty($apiExtra) ? $apiExtra : null,
                    'is_active'  => true,
                ];

                if ($existing) {
                    $existing->update($data);
                } else {
                    SupplierPaymentSetting::create(array_merge($data, [
                        'supplier_id' => $supplier->id,
                    ]));
                }
            }

            $processedSupplierIds[] = $supplier->id;
            $imported++;
        }

        $this->newLine();
        $this->info('--- Resultado ---');
        $this->info($isDryRun
            ? "Seriam importados/atualizados: $imported | Pulados: $skipped | Sem match: $noMatch | Sem credenciais: $noCredentials"
            : "Importados/atualizados: $imported | Pulados: $skipped | Sem match: $noMatch | Sem credenciais: $noCredentials"
        );

        if ($noMatch > 0) {
            $this->warn("$noMatch token(s) sem fornecedor correspondente no novo sistema.");
        }
        if ($skipped > 0 && !$isForce) {
            $this->info('Use --force para sobrescrever registros existentes.');
        }

        return self::SUCCESS;
    }
}
