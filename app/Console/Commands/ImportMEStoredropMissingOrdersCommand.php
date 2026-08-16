<?php

namespace App\Console\Commands;

use App\Models\Client;
use App\Models\Order;
use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/**
 * NOV-109 - Importa pedidos MEStoreDrop faltantes do legado para hubaiapp.orders.
 *
 * Legado: ~29.097 pedidos (pedidos JOIN integracao WHERE integracao.id_empresa=20).
 * Novo:   ~25.768 pedidos (supplier_id=25, legacy_id IS NOT NULL).
 * Faltam: ~3.329 pedidos.
 *
 * Uso:
 *   php artisan import:mestoredrop-missing-orders --dry-run
 *   php artisan import:mestoredrop-missing-orders --limit=100
 *   php artisan import:mestoredrop-missing-orders
 *
 * Background:
 *   nohup php artisan import:mestoredrop-missing-orders >> /home/api.hubai.io/logs/mestoredrop-orders.log 2>&1 &
 */
class ImportMEStoredropMissingOrdersCommand extends Command
{
    protected $signature = 'import:mestoredrop-missing-orders
                            {--dry-run : Simula sem gravar nada no banco}
                            {--limit=0 : Limita o numero de pedidos a importar (0=todos)}
                            {--login= : Processa somente um id_login especifico}';

    protected $description = 'NOV-109: Importa pedidos MEStoreDrop faltantes do legado (id_empresa=20)';

    private const ID_EMPRESA    = 20;
    private const SUPPLIER_ID   = 25;
    private const TENANT_SLUG   = 'mestoredrop';
    private const CHUNK_SIZE    = 50;

    private const CANAL_PLATFORM = [
        1  => 'magalu',  2  => 'bling',   3  => 'shopee',  5  => 'shopee',
        6  => 'ml',      7  => 'b2w',     8  => 'magalu',  9  => 'shopify',
        11 => 'shopify', 12 => 'ml',      14 => 'bling',   15 => 'bling',
        16 => 'bling',   18 => 'bling',   19 => 'bling',   20 => 'bling',
    ];

    private const STATUS_MAP = [
        0 => 'created',
        1 => 'shipped',
        2 => 'cancelled',
        3 => 'delivered',
        4 => 'returned',
    ];

    private int $ordersInserted  = 0;
    private int $ordersSkipped   = 0;
    private int $clientsCreated  = 0;
    private int $clientsExisting = 0;
    private int $loginsSem       = 0;

    public function handle(): int
    {
        $dryRun    = (bool) $this->option('dry-run');
        $limit     = (int)  $this->option('limit');
        $loginOnly = $this->option('login') !== null ? (int) $this->option('login') : null;

        $this->info('=== import:mestoredrop-missing-orders ===');
        $this->info('  supplier_id : ' . self::SUPPLIER_ID);
        $this->info('  id_empresa  : ' . self::ID_EMPRESA);
        $this->info('  dry-run     : ' . ($dryRun ? 'SIM' : 'nao'));
        $this->info('  limit       : ' . ($limit > 0 ? $limit : 'sem limite'));
        if ($loginOnly !== null) {
            $this->info('  login-only  : ' . $loginOnly);
        }
        $this->newLine();

        $loginsQuery = DB::connection('legacy')
            ->table('pedidos as p')
            ->join('integracao as i', 'p.id_integracao', '=', 'i.id')
            ->where('i.id_empresa', self::ID_EMPRESA)
            ->selectRaw('DISTINCT i.id_login as id_login')
            ->orderBy('i.id_login');

        if ($loginOnly !== null) {
            $loginsQuery->where('i.id_login', $loginOnly);
        }

        $logins = $loginsQuery->pluck('id_login')->toArray();
        $this->info('Lojas distintas com pedidos MEStoreDrop no legado: ' . count($logins));

        $importedIds = DB::table('orders')
            ->where('supplier_id', self::SUPPLIER_ID)
            ->whereNotNull('legacy_id')
            ->pluck('legacy_id')
            ->flip()
            ->toArray();

        $this->info('Pedidos ja importados no novo: ' . count($importedIds));
        $this->newLine();

        $totalLimit = $limit;
        foreach ($logins as $idLogin) {
            if ($totalLimit > 0 && $this->ordersInserted >= $totalLimit) {
                $this->info('Limite de ' . $limit . ' pedidos atingido. Parando.');
                break;
            }
            $this->processLogin((int) $idLogin, $importedIds, $dryRun, $totalLimit);
        }

        $this->newLine();
        $this->info('================================================');
        $this->info('NOV-109 - RESUMO FINAL');
        $this->info('================================================');
        $this->line('  Clientes criados    : ' . $this->clientsCreated);
        $this->line('  Clientes existentes : ' . $this->clientsExisting);
        $this->line('  Lojas sem email     : ' . $this->loginsSem);
        $this->line('  Pedidos inseridos   : ' . $this->ordersInserted);
        $this->line('  Pedidos ja existiam : ' . $this->ordersSkipped);
        $this->info($dryRun ? '[DRY-RUN] Nenhum dado gravado.' : 'Importacao concluida.');
        $this->info('================================================');

        return self::SUCCESS;
    }

    private function processLogin(int $idLogin, array &$importedIds, bool $dryRun, int $totalLimit): void
    {
        $client = $this->ensureClient($idLogin, $dryRun);
        if ($client === null) {
            $this->loginsSem++;
            $this->warn('  SKIP id_login=' . $idLogin . ': nao foi possivel criar/encontrar cliente');
            return;
        }

        $pedidos = DB::connection('legacy')
            ->table('pedidos as p')
            ->join('integracao as i', 'p.id_integracao', '=', 'i.id')
            ->where('i.id_empresa', self::ID_EMPRESA)
            ->where('i.id_login', $idLogin)
            ->select([
                'p.id as legacy_id',
                'p.id_canal',
                'p.nr_canal',
                'p.status',
                'p.valor_total',
                'p.cliente_nome',
                'p.cliente_cpf',
                'p.rastreio',
                'p.data_pedido_canal',
                'p.data_add',
            ])
            ->orderBy('p.id')
            ->get();

        $faltando = $pedidos->filter(fn ($p) => ! array_key_exists($p->legacy_id, $importedIds));

        if ($faltando->isEmpty()) {
            return;
        }

        $this->line(sprintf(
            '  id_login=%d | total_legado=%d | existentes=%d | a_inserir=%d',
            $idLogin,
            $pedidos->count(),
            $pedidos->count() - $faltando->count(),
            $faltando->count()
        ));

        if ($dryRun) {
            $this->ordersInserted += $faltando->count();
            return;
        }

        $now = now()->toDateTimeString();
        foreach ($faltando->chunk(self::CHUNK_SIZE) as $chunk) {
            if ($totalLimit > 0 && $this->ordersInserted >= $totalLimit) {
                break;
            }

            $rows = [];
            foreach ($chunk as $pedido) {
                if ($totalLimit > 0 && ($this->ordersInserted + count($rows)) >= $totalLimit) {
                    break;
                }

                $platform        = self::CANAL_PLATFORM[$pedido->id_canal] ?? 'other';
                $canonicalStatus = self::STATUS_MAP[$pedido->status] ?? 'created';
                $orderDate       = $pedido->data_pedido_canal ?? $pedido->data_add ?? $now;
                $orderNumber     = $pedido->nr_canal ?? ('LEG-' . $pedido->legacy_id);

                $rows[] = [
                    'legacy_id'               => $pedido->legacy_id,
                    'client_id'               => $client->id,
                    'supplier_id'             => self::SUPPLIER_ID,
                    'tenant_slug'             => self::TENANT_SLUG,
                    'order_number'            => $orderNumber,
                    'source'                  => $platform,
                    'external_order_id'       => $pedido->nr_canal ?? null,
                    'customer_name'           => $pedido->cliente_nome ? mb_substr($pedido->cliente_nome, 0, 254) : null,
                    'customer_document_number' => $pedido->cliente_cpf ? mb_substr($pedido->cliente_cpf, 0, 254) : null,
                    'tracking_number'         => $pedido->rastreio ? mb_substr($pedido->rastreio, 0, 254) : null,
                    'status'                  => $canonicalStatus,
                    'canonical_status'        => $canonicalStatus,
                    'subtotal'                => (float) ($pedido->valor_total ?? 0),
                    'total'                   => (float) ($pedido->valor_total ?? 0),
                    'currency'                => 'BRL',
                    'order_processing_status' => 'awaiting_label',
                    'created_at'              => $orderDate,
                    'updated_at'              => $now,
                ];
            }

            if (empty($rows)) {
                break;
            }

            try {
                DB::table('orders')->insertOrIgnore($rows);
                $this->ordersInserted += count($rows);
                foreach ($rows as $row) {
                    $importedIds[$row['legacy_id']] = true;
                }
            } catch (\Throwable $e) {
                $this->error('  ERRO chunk id_login=' . $idLogin . ': ' . $e->getMessage());
            }
        }
    }

    private function ensureClient(int $idLogin, bool $dryRun): ?object
    {
        $existing = Client::where('legacy_id_login', $idLogin)->first();
        if ($existing) {
            $this->clientsExisting++;
            return $existing;
        }

        $legacyLogin = DB::connection('legacy')
            ->table('login')
            ->where('id', $idLogin)
            ->select(['id', 'email', 'nome_completo', 'celular', 'cpf_cnpj', 'status', 'senha'])
            ->first();

        if (! $legacyLogin) {
            $legacyLogin = DB::connection('legacy')
                ->table('loja')
                ->where('id', $idLogin)
                ->selectRaw('id, email, nome as nome_completo, celular, cpf_cnpj, 1 as status, 0 as bloqueado, senha')
                ->first();
        }

        $email = $legacyLogin ? trim(strtolower($legacyLogin->email ?? '')) : '';
        if (! $email || ! filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $email = 'legacy.login.' . $idLogin . '@mestoredrop.import';
        }

        $nome = ($legacyLogin->nome_completo ?? '') ?: 'Cliente Legado #' . $idLogin;

        if ($dryRun) {
            $this->line('  [DRY-CREATE] id_login=' . $idLogin . ' email=' . $email . ' nome=' . $nome);
            $this->clientsCreated++;
            return (object) ['id' => 0];
        }

        try {
            $client = DB::transaction(function () use ($idLogin, $email, $nome, $legacyLogin) {
                $user = User::firstOrCreate(
                    ['email' => $email],
                    [
                        'name'      => mb_substr($nome, 0, 254),
                        'password'  => Hash::make(Str::random(24)),
                        'role'      => 'client',
                        'is_active' => true,
                    ]
                );

                // MUL-269 fase 2: company_name removido de clients — nome vem do user (accessor).
                $client = Client::firstOrCreate(
                    ['user_id' => $user->id],
                    [
                        'legacy_id_login' => $idLogin,
                        'phone'           => $legacyLogin->celular ?? null,
                        'document'        => $legacyLogin->cpf_cnpj ?? null,
                        'is_active'       => true,
                        'listing_mode'    => 'manual',
                    ]
                );

                if (! $client->legacy_id_login) {
                    $client->update(['legacy_id_login' => $idLogin]);
                }

                return $client;
            });

            $this->clientsCreated++;
            $this->line('  [CREATE] id_login=' . $idLogin . ' client_id=' . $client->id . ' email=' . $email);
            return $client;

        } catch (\Throwable $e) {
            $this->error('  ERRO criar cliente id_login=' . $idLogin . ': ' . $e->getMessage());
            return null;
        }
    }
}
