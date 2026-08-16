<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * NOV-217 — Proxy Laravel do wl-balance (Supabase edge function).
 * Retorna saldo devedor + ciclos + lancamentos de uma Whitelabel.
 *
 * GET /api/v1/wl/balance/{empresa_id}?token=X
 *
 * Autenticacao: token simples via query param ou header X-WL-Token.
 * Acessa Supabase HubAI (omvstizxjosygkcolzzl) diretamente via REST.
 */
class WlBalanceController extends Controller
{
    private string $supabaseUrl;
    private string $supabaseServiceKey;
    private string $supabaseAnonKey;

    public function __construct()
    {
        $this->supabaseUrl        = config('services.supabase_hubai.url', 'https://omvstizxjosygkcolzzl.supabase.co');
        $this->supabaseServiceKey = config('services.supabase_hubai.service_role', '');
        $this->supabaseAnonKey    = config('services.supabase_hubai.anon_key', '');
    }

    /** Retorna a chave adequada: service_role > anon */
    private function key(): string
    {
        return $this->supabaseServiceKey ?: $this->supabaseAnonKey;
    }

    private function sbHeaders(): array
    {
        $k = $this->key();
        return [
            'apikey'        => $k,
            'Authorization' => 'Bearer ' . $k,
            'Content-Type'  => 'application/json',
        ];
    }

    /**
     * GET /api/v1/wl/balance/{empresa_id}
     * Compativel com chamadas do front hubai.io/cobranca-wl?emp=X&token=Y
     */
    public function show(Request $request, int $empresaId)
    {
        $token = $request->query('token') ?: $request->header('X-WL-Token', '');
        if (!$this->validateWlToken($token, $empresaId)) {
            return response()->json(['ok' => false, 'error' => 'Token invalido ou expirado'], 401);
        }

        try {
            $sbUrl   = rtrim($this->supabaseUrl, '/');
            $headers = $this->sbHeaders();

            [$balance, $lancamentos, $ciclos, $billingConfig, $todosLancamentos] = $this->fetchBalanceData($sbUrl, $headers, $empresaId);

            $precoPorUsuario = (float)($billingConfig['preco_por_usuario'] ?? 30);
            $precoPorPedido  = (float)($billingConfig['preco_por_pedido'] ?? 0.99);

            // Calculo saldo correto (idenxico a edge function wl-balance)
            $statusCobravel = ['closed', 'pending'];
            $totalCiclosDevedores = 0;
            foreach ($ciclos as $ciclo) {
                if (!in_array($ciclo['status'] ?? '', $statusCobravel)) continue;
                if ($ciclo['active_users_snapshot'] === null && $ciclo['orders_snapshot'] === null) continue;
                $users    = (float)($ciclo['active_users_snapshot'] ?? 0);
                $orders   = (float)($ciclo['orders_snapshot'] ?? 0);
                $desconto = (float)($ciclo['desconto'] ?? 0);
                $totalCiclosDevedores += ($users * $precoPorUsuario) + ($orders * $precoPorPedido) - $desconto;
            }

            $totalDebitosNaoCiclo = 0;
            $totalCreditosReais   = 0;
            foreach ($todosLancamentos as $l) {
                if (($l['source'] ?? '') === 'billing_cycle') continue;
                if (($l['entry_type'] ?? '') === 'debit') {
                    $totalDebitosNaoCiclo += (float)($l['amount'] ?? 0);
                } elseif (($l['entry_type'] ?? '') === 'credit') {
                    $totalCreditosReais += (float)($l['amount'] ?? 0);
                }
            }

            $saldoCorreto = max(0, $totalCiclosDevedores + $totalDebitosNaoCiclo - $totalCreditosReais);
            $saldoCorreto = round($saldoCorreto, 2);

            // Atualiza whitelabel_balances (igual a edge function)
            $this->upsertBalance($sbUrl, $headers, $empresaId, $saldoCorreto, $totalCiclosDevedores + $totalDebitosNaoCiclo, $totalCreditosReais);

            return response()->json([
                'ok'                   => true,
                'empresa_id'           => $empresaId,
                'saldo_devedor'        => $saldoCorreto,
                'saldo_devedor_legado' => (float)($balance['saldo_devedor'] ?? 0),
                'total_debitos'        => $totalCiclosDevedores + $totalDebitosNaoCiclo,
                'total_creditos'       => $totalCreditosReais,
                'ultima_movimentacao'  => now()->toISOString(),
                'lancamentos'          => $lancamentos,
                'ciclos'               => $ciclos,
                'preco_por_usuario'    => $precoPorUsuario,
                'preco_por_pedido'     => $precoPorPedido,
                'empresa_nome'         => $billingConfig['empresa_nome'] ?? null,
                '_calculo'             => [
                    'total_ciclos_devedores'  => $totalCiclosDevedores,
                    'total_debitos_nao_ciclo' => $totalDebitosNaoCiclo,
                    'total_creditos_reais'    => $totalCreditosReais,
                    'saldo_correto'           => $saldoCorreto,
                ],
            ]);
        } catch (\Throwable $e) {
            Log::error('[WlBalance] erro empresa=' . $empresaId . ': ' . $e->getMessage());
            return response()->json(['ok' => false, 'error' => $e->getMessage()], 500);
        }
    }

    private function validateWlToken(string $token, int $empresaId): bool
    {
        if (empty($token)) return false;
        $sbUrl   = rtrim($this->supabaseUrl, '/');
        $headers = $this->sbHeaders();
        $resp = Http::withHeaders($headers)
            ->get($sbUrl . '/rest/v1/whitelabel_billing_config', [
                'empresa_id' => 'eq.' . $empresaId,
                'select'     => 'access_token',
            ]);
        if (!$resp->successful()) return false;
        $data        = $resp->json();
        $storedToken = $data[0]['access_token'] ?? '';
        return $storedToken && hash_equals($storedToken, $token);
    }

    private function fetchBalanceData(string $sbUrl, array $headers, int $empresaId): array
    {
        $base = $sbUrl . '/rest/v1/';

        $balance = Http::withHeaders($headers)
            ->get($base . 'whitelabel_balances', ['empresa_id' => 'eq.' . $empresaId, 'limit' => 1])
            ->json();
        $balance = $balance[0] ?? [];

        $lancamentos = Http::withHeaders($headers)
            ->get($base . 'whitelabel_ledger', [
                'empresa_id' => 'eq.' . $empresaId,
                'source'     => 'neq.payment_pending',
                'order'      => 'created_at.desc',
                'limit'      => 20,
                'select'     => 'id,entry_type,amount,source,source_ref,description,created_at,meta',
            ])->json() ?? [];

        $ciclos = Http::withHeaders($headers)
            ->get($base . 'whitelabel_billing_cycles', [
                'empresa_id' => 'eq.' . $empresaId,
                'order'      => 'cycle_start.desc',
                'limit'      => 50,
                'select'     => 'id,cycle_start,cycle_end,status,active_users_snapshot,orders_snapshot,amount_due,amount_paid,desconto,vencimento,notas',
            ])->json() ?? [];

        $billingConfig = Http::withHeaders($headers)
            ->get($base . 'whitelabel_billing_config', [
                'empresa_id' => 'eq.' . $empresaId,
                'limit'      => 1,
                'select'     => 'preco_por_usuario,preco_por_pedido,empresa_nome',
            ])->json();
        $billingConfig = $billingConfig[0] ?? [];

        $todosLancamentos = Http::withHeaders($headers)
            ->get($base . 'whitelabel_ledger', [
                'empresa_id' => 'eq.' . $empresaId,
                'source'     => 'neq.payment_pending',
                'select'     => 'id,entry_type,amount,source',
            ])->json() ?? [];

        return [$balance, $lancamentos, $ciclos, $billingConfig, $todosLancamentos];
    }

    private function upsertBalance(string $sbUrl, array $headers, int $empresaId, float $saldo, float $debitos, float $creditos): void
    {
        try {
            Http::withHeaders(array_merge($headers, ['Prefer' => 'resolution=merge-duplicates,return=minimal']))
                ->post($sbUrl . '/rest/v1/whitelabel_balances', [
                    'empresa_id'          => $empresaId,
                    'saldo_devedor'       => $saldo,
                    'total_debitos'       => $debitos,
                    'total_creditos'      => $creditos,
                    'ultima_movimentacao' => now()->toISOString(),
                ]);
        } catch (\Throwable $e) {
            Log::warning('[WlBalance] upsert balance falhou: ' . $e->getMessage());
        }
    }
}
