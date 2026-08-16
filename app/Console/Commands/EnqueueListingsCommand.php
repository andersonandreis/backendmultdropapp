<?php

namespace App\Console\Commands;

use App\Models\Client;
use App\Models\ClientProduct;
use App\Models\MarketplaceAccount;
use App\Models\ProductListingJob;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * NOV-072 - Robo de Cadastro v2
 *
 * Enfileira ClientProducts na product_listing_jobs para publicacao automatica.
 *
 * Criterio de elegibilidade:
 * - sync_status IN ('draft', 'ready', 'pending')
 * - external_listing_id IS NULL (nao publicado)
 * - is_active = 1
 * - Sem job pending/processing/done para o mesmo client_product_id
 *
 * Uso:
 *   php artisan hubai:enqueue-listings
 *   php artisan hubai:enqueue-listings 42
 *   php artisan hubai:enqueue-listings --marketplace=ml
 *   php artisan hubai:enqueue-listings --speed=fast --limit=200 --generate-image=1
 */
class EnqueueListingsCommand extends Command
{
    protected $signature = 'hubai:enqueue-listings
        {client_id? : ID do cliente (omitir para todos os clientes ativos)}
        {--marketplace=all : Plataforma: all | ml | shopee}
        {--speed=normal : Velocidade: slow | normal | fast}
        {--generate-image=0 : Gerar titulo+descricao com IA antes de publicar (0|1)}
        {--limit=100 : Maximo de itens a enfileirar por cliente}';

    protected $description = 'NOV-072: Enfileira ClientProducts para publicacao automatica nos marketplaces';

    public function handle(): int
    {
        $clientId      = $this->argument('client_id');
        $marketplace   = $this->option('marketplace');
        $speed         = $this->option('speed');
        $generateImage = (int) $this->option('generate-image');
        $limit         = (int) $this->option('limit');

        if (! in_array($speed, ['slow', 'normal', 'fast'])) {
            $this->error('--speed deve ser: slow | normal | fast');
            return self::FAILURE;
        }

        $platformFilter = match ($marketplace) {
            'ml'     => ['mercadolivre', 'mercado_livre'],
            'shopee' => ['shopee'],
            default  => null, // all
        };

        $clientQuery = Client::query();
        if ($clientId) {
            $clientQuery->where('id', $clientId);
        }
        $clients = $clientQuery->get();

        if ($clients->isEmpty()) {
            $this->warn('Nenhum cliente encontrado' . ($clientId ? " com id={$clientId}" : '.'));
            return self::SUCCESS;
        }

        $totalEnqueued = 0;
        $totalSkipped  = 0;

        foreach ($clients as $client) {
            $result = $this->enqueueForClient($client, $platformFilter, $speed, $generateImage, $limit);
            $totalEnqueued += $result['enqueued'];
            $totalSkipped  += $result['skipped'];
        }

        $this->info("Concluido: {$totalEnqueued} enfileirados | {$totalSkipped} ignorados (ja na fila ou publicados).");
        return self::SUCCESS;
    }

    private function enqueueForClient(
        Client $client,
        ?array $platformFilter,
        string $speed,
        int $generateImage,
        int $limit
    ): array {
        $accountQuery = MarketplaceAccount::where('client_id', $client->id)
            ->whereIn('status', ['active', 'connected']);

        if ($platformFilter !== null) {
            $accountQuery->whereIn('platform', $platformFilter);
        }

        $accounts = $accountQuery->get();

        if ($accounts->isEmpty()) {
            $this->line("  Cliente #{$client->id}: sem contas de marketplace elegiveis.");
            return ['enqueued' => 0, 'skipped' => 0];
        }

        $enqueued = 0;
        $skipped  = 0;

        foreach ($accounts as $account) {
            $alreadyQueued = ProductListingJob::where('marketplace_account_id', $account->id)
                ->whereIn('status', ['pending', 'processing', 'done'])
                ->pluck('client_product_id')
                ->toArray();

            $eligibleProducts = ClientProduct::where('marketplace_account_id', $account->id)
                ->whereNull('external_listing_id')
                ->whereIn('sync_status', ['draft', 'ready', 'pending'])
                ->where('is_active', true)
                ->whereNotIn('id', $alreadyQueued)
                ->limit($limit)
                ->get();

            if ($eligibleProducts->isEmpty()) {
                $skipped++;
                continue;
            }

            $now  = now();
            $rows = $eligibleProducts->map(fn ($cp) => [
                'client_id'              => $client->id,
                'marketplace_account_id' => $account->id,
                'client_product_id'      => $cp->id,
                'status'                 => 'pending',
                'attempt'                => 0,
                'generate_image'         => $generateImage,
                'speed'                  => $speed,
                'created_at'             => $now,
                'updated_at'             => $now,
            ])->toArray();

            DB::table('product_listing_jobs')->insert($rows);

            $count = count($rows);
            $enqueued += $count;

            $this->line("  Cliente #{$client->id} | Conta #{$account->id} ({$account->platform}): {$count} produtos enfileirados.");
        }

        return ['enqueued' => $enqueued, 'skipped' => $skipped];
    }
}
