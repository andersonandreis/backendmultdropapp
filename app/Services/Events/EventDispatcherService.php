<?php

namespace App\Services\Events;

use App\Jobs\DispatchEventNotificationJob;
use App\Models\EventSubscription;

/**
 * Dispatcher central de eventos internos do HubAI.
 *
 * Responsabilidades:
 * - Localizar todas as EventSubscriptions ativas para o evento disparado
 * - Respeitar o escopo de supplier_id (null = super admin recebe tudo)
 * - Despachar DispatchEventNotificationJob de forma async para cada canal configurado
 */
class EventDispatcherService
{
    /**
     * Dispara um evento para todos os inscritos elegíveis.
     *
     * @param  string    $eventType  Ex: 'order.created', 'payment.confirmed'
     * @param  array     $payload    Dados do evento (serializáveis)
     * @param  int|null  $supplierId Fornecedor que originou o evento; null = evento global
     */
    public function dispatch(string $eventType, array $payload, ?int $supplierId = null): void
    {
        $subscriptions = EventSubscription::active()
            ->forEvent($eventType)
            ->when(
                $supplierId,
                // Evento de supplier: entrega pro próprio supplier E para admins (supplier_id null)
                fn ($q) => $q->where(function ($inner) use ($supplierId) {
                    $inner->where('supplier_id', $supplierId)
                          ->orWhereNull('supplier_id');
                }),
                // Evento global (sem supplier): só entrega para admins (supplier_id null)
                fn ($q) => $q->whereNull('supplier_id')
            )
            ->get();

        foreach ($subscriptions as $subscription) {
            $channels = $subscription->channels ?? [];

            foreach ($channels as $channel) {
                DispatchEventNotificationJob::dispatch(
                    $subscription->id,
                    $eventType,
                    $payload,
                    $channel,
                );
            }
        }
    }

    /**
     * Lista todos os tipos de evento disponíveis para inscrição,
     * agrupados por categoria.
     *
     * @return array<string, array<string, string>>
     */
    public static function availableEvents(): array
    {
        return [
            'Pedidos' => [
                'order.created'   => 'Pedido criado',
                'order.paid'      => 'Pedido pago',
                'order.shipped'   => 'Pedido enviado',
                'order.delivered' => 'Pedido entregue',
                'order.cancelled' => 'Pedido cancelado',
                'order.returned'  => 'Pedido devolvido',
            ],
            'Pagamentos' => [
                'payment.confirmed'     => 'Pagamento confirmado',
                'payment.failed'        => 'Pagamento falhou',
                'payment.refunded'      => 'Pagamento estornado',
                'payment.expired'       => 'PIX expirado',
                'payment.batch_completed' => 'Batch de pagamentos concluido',
            ],
            'Wallet' => [
                'wallet.topup'       => 'Recarga de wallet',
                'wallet.debit'       => 'Débito na wallet',
                'wallet.low_balance' => 'Saldo baixo',
            ],
            'Saques' => [
                'withdrawal.requested' => 'Saque solicitado',
                'withdrawal.approved'  => 'Saque aprovado',
                'withdrawal.rejected'  => 'Saque rejeitado',
                'withdrawal.paid'      => 'Saque pago',
            ],
            'Marketplace' => [
                'listing.created'  => 'Anúncio criado',
                'listing.updated'  => 'Anúncio atualizado',
                'stock.low'        => 'Estoque baixo',
                'stock.synced'     => 'Estoque sincronizado',
            ],
            'Sistema' => [
                'subscription.renewed'   => 'Assinatura renovada',
                'subscription.cancelled' => 'Assinatura cancelada',
                'plan.upgraded'          => 'Plano atualizado',
            ],
        ];
    }

    /**
     * Retorna o label legível de um eventType, ou o próprio tipo se não encontrado.
     */
    public static function labelFor(string $eventType): string
    {
        foreach (self::availableEvents() as $events) {
            if (isset($events[$eventType])) {
                return $events[$eventType];
            }
        }

        return $eventType;
    }
}
