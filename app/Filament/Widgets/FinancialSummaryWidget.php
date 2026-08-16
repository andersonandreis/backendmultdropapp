<?php

namespace App\Filament\Widgets;

use Filament\Widgets\Widget;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

class FinancialSummaryWidget extends Widget
{
    protected static string $view = 'filament.widgets.financial-summary-widget';
    protected static ?int $sort = 5;
    protected int|string|array $columnSpan = 2;
    protected static ?string $heading = 'Resumo Financeiro';

    public array $data = [];
    public string $lastUpdated = '';

    public function mount(): void
    {
        $this->loadData();
    }

    public function loadData(): void
    {
        try {
            $this->data = Cache::remember('centro_comando_financial', 60, function () {
                $out = [];

                // Saques pendentes
                try {
                    if (DB::getSchemaBuilder()->hasTable('withdrawal_requests')) {
                        $saques = DB::table('withdrawal_requests')
                            ->where('status', 'pending')
                            ->selectRaw('COUNT(*) as qtd, COALESCE(SUM(amount), 0) as total')
                            ->first();
                        $out['saques_pendentes_qtd']   = $saques?->qtd ?? 0;
                        $out['saques_pendentes_valor'] = (float)($saques?->total ?? 0);
                    } else {
                        $out['saques_pendentes_qtd']   = 0;
                        $out['saques_pendentes_valor'] = 0;
                    }
                } catch (\Throwable) {
                    $out['saques_pendentes_qtd']   = 0;
                    $out['saques_pendentes_valor'] = 0;
                }

                // Saldo total em carteiras (top 5 fornecedores)
                try {
                    if (DB::getSchemaBuilder()->hasTable('wallets')) {
                        $saldos = DB::table('wallets')
                            ->join('users', 'wallets.user_id', '=', 'users.id')
                            ->where('wallets.balance', '>', 0)
                            ->orderByDesc('wallets.balance')
                            ->limit(5)
                            ->get(['users.name', 'wallets.balance'])
                            ->map(fn($r) => ['name' => $r->name, 'balance' => (float)$r->balance])
                            ->toArray();
                        $out['top_saldos'] = $saldos;
                        $out['saldo_total'] = DB::table('wallets')->sum('balance');
                    } else {
                        $out['top_saldos'] = [];
                        $out['saldo_total'] = 0;
                    }
                } catch (\Throwable) {
                    $out['top_saldos'] = [];
                    $out['saldo_total'] = 0;
                }

                // Receita hoje e 7 dias (subscriptions/payments)
                try {
                    if (DB::getSchemaBuilder()->hasTable('transactions')) {
                        $out['receita_hoje'] = (float) DB::table('transactions')
                            ->where('type', 'credit')
                            ->where('status', 'completed')
                            ->whereDate('created_at', today())
                            ->sum('amount');

                        $out['receita_7d'] = (float) DB::table('transactions')
                            ->where('type', 'credit')
                            ->where('status', 'completed')
                            ->where('created_at', '>=', now()->subDays(7))
                            ->sum('amount');
                    } elseif (DB::getSchemaBuilder()->hasTable('payments')) {
                        $out['receita_hoje'] = (float) DB::table('payments')
                            ->where('status', 'paid')
                            ->whereDate('created_at', today())
                            ->sum('amount');

                        $out['receita_7d'] = (float) DB::table('payments')
                            ->where('status', 'paid')
                            ->where('created_at', '>=', now()->subDays(7))
                            ->sum('amount');
                    } else {
                        $out['receita_hoje'] = 0;
                        $out['receita_7d']   = 0;
                    }
                } catch (\Throwable) {
                    $out['receita_hoje'] = 0;
                    $out['receita_7d']   = 0;
                }

                // Fallback: tentar endpoint interno
                if ($out['receita_hoje'] == 0 && $out['receita_7d'] == 0) {
                    try {
                        $key = config('services.internal_bridge_key');
                        if ($key) {
                            $response = \Illuminate\Support\Facades\Http::timeout(5)
                                ->withHeaders(['X-Internal-Key' => $key])
                                ->get(url('/api/internal/financial-summary'));
                            if ($response->successful()) {
                                $payload = $response->json();
                                $out['receita_hoje'] = (float)($payload['revenue_today'] ?? 0);
                                $out['receita_7d']   = (float)($payload['revenue_7d']    ?? 0);
                                if (isset($payload['withdrawals_pending'])) {
                                    $out['saques_pendentes_qtd']   = $payload['withdrawals_pending']['count'] ?? $out['saques_pendentes_qtd'];
                                    $out['saques_pendentes_valor'] = $payload['withdrawals_pending']['amount'] ?? $out['saques_pendentes_valor'];
                                }
                            }
                        }
                    } catch (\Throwable) {}
                }

                return $out;
            });
        } catch (\Throwable) {
            $this->data = [
                'saques_pendentes_qtd'   => 0,
                'saques_pendentes_valor' => 0,
                'top_saldos'             => [],
                'saldo_total'            => 0,
                'receita_hoje'           => 0,
                'receita_7d'             => 0,
            ];
        }

        $this->lastUpdated = now()->format('H:i:s');
    }

    public static function canView(): bool
    {
        return auth()->user()?->role === 'super_admin';
    }
}
