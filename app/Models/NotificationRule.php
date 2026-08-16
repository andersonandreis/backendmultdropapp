<?php

namespace App\Models;

use Carbon\Carbon;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Cache;

/**
 * MUL-226-03: regra de notificação por categoria/evento/janela/canal.
 * `isAllowedNow()` é o único ponto de decisão — quem envia notificação
 * (job, command, controller) chama antes de disparar.
 */
class NotificationRule extends Model
{
    protected $fillable = [
        'supplier_id',
        'category',
        'event',
        'days_of_week',
        'time_start',
        'time_end',
        'channel',
        'enabled',
        'extra',
        'created_by',
    ];

    protected $casts = [
        'days_of_week' => 'array',
        'extra'        => 'array',
        'enabled'      => 'boolean',
    ];

    public const CATEGORIES = [
        'system'    => 'Sistema (integrações dessincronizadas)',
        'orders'    => 'Pedidos (sem etiqueta / não pago no corte)',
        'products'  => 'Produtos (falta / reposição / desativação)',
        'financial' => 'Financeiro (saldo baixo carteira AutoPay)',
    ];

    public const EVENTS = [
        'system'    => [
            'integration_desync' => 'Integração dessincronizada (ML/Shopee/Bling)',
        ],
        'orders'    => [
            'order_no_label'      => 'Pedido pago sem etiqueta',
            'order_unpaid_cutoff' => 'Pedido não pago no horário de corte',
        ],
        'products'  => [
            'product_out_of_stock' => 'Produto sem estoque (real = 0)',
            'product_low_stock'    => 'Produto em reposição (abaixo do limite)',
            'product_deactivated'  => 'Produto desativado (marketplace)',
        ],
        'financial' => [
            'wallet_low_balance' => 'Saldo baixo na carteira AutoPay',
        ],
    ];

    public const CHANNELS = [
        'email' => 'E-mail',
        'push'  => 'Push',
        'both'  => 'E-mail + Push',
    ];

    public const DAYS = [
        'mon' => 'Segunda',
        'tue' => 'Terça',
        'wed' => 'Quarta',
        'thu' => 'Quinta',
        'fri' => 'Sexta',
        'sat' => 'Sábado',
        'sun' => 'Domingo',
    ];

    private const DAY_MAP = [1 => 'mon', 2 => 'tue', 3 => 'wed', 4 => 'thu', 5 => 'fri', 6 => 'sat', 0 => 'sun'];

    public static function ruleFor(string $category, string $event, ?int $supplierId = null): ?self
    {
        $key = "notif_rule:{$category}:{$event}:" . ($supplierId ?? 'global');

        return Cache::remember($key, 60, function () use ($category, $event, $supplierId) {
            $q = static::where('category', $category)->where('event', $event);

            if ($supplierId) {
                $rule = (clone $q)->where('supplier_id', $supplierId)->first();
                if ($rule) {
                    return $rule;
                }
            }

            return (clone $q)->whereNull('supplier_id')->first();
        });
    }

    /**
     * Decide se uma notificação deste evento pode ser enviada AGORA (dia + horário + habilitada).
     * Sem regra cadastrada => TRUE (comportamento padrão: notifica sempre, não bloqueia).
     */
    public static function isAllowedNow(string $category, string $event, ?int $supplierId = null, ?Carbon $moment = null): bool
    {
        $rule = self::ruleFor($category, $event, $supplierId);
        if (! $rule) {
            return true;
        }

        return $rule->allows($moment ?? now());
    }

    public function allows(Carbon $moment): bool
    {
        if (! $this->enabled) {
            return false;
        }

        $days = $this->days_of_week ?? array_keys(self::DAYS);
        $today = self::DAY_MAP[(int) $moment->dayOfWeek] ?? null;
        if ($today === null || ! in_array($today, $days, true)) {
            return false;
        }

        $now = $moment->format('H:i:s');
        $start = $this->time_start ?: '00:00:00';
        $end = $this->time_end ?: '23:59:59';

        if ($start <= $end) {
            return $now >= $start && $now <= $end;
        }

        // Janela que atravessa a meia-noite (ex: 22:00 → 06:00)
        return $now >= $start || $now <= $end;
    }

    public static function forgetCacheFor(string $category, string $event, ?int $supplierId = null): void
    {
        Cache::forget("notif_rule:{$category}:{$event}:" . ($supplierId ?? 'global'));
    }
}
