<?php

namespace Database\Factories;

use App\Models\Client;
use App\Models\MarketplaceAccount;
use App\Models\MissedOrderAlert;
use App\Models\Order;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<MissedOrderAlert>
 */
class MissedOrderAlertFactory extends Factory
{
    protected $model = MissedOrderAlert::class;

    private static array $marketplaces = ['shopee', 'ml', 'bling'];

    public function definition(): array
    {
        $marketplace = fake()->randomElement(self::$marketplaces);

        return [
            'client_id'              => Client::factory(),
            'marketplace_account_id' => null,
            'marketplace'            => $marketplace,
            'marketplace_order_id'   => strtoupper(fake()->bothify('??##########')),
            'buyer_name'             => fake()->name(),
            'buyer_doc'              => fake()->numerify('###.###.###-##'),
            'amount_cents'           => fake()->numberBetween(1000, 50000), // R$10 – R$500
            'currency'               => 'BRL',
            'dismiss_reason'         => null,
            'detected_at'            => now()->subHours(fake()->numberBetween(1, 48)),
            'notified_at'            => null,
            'dismissed_at'           => null,
            'converted_to_order_id'  => null,
            'notification_count'     => 0,
            'payload'                => [
                'source'    => $marketplace,
                'raw_total' => fake()->randomFloat(2, 10, 500),
                'items'     => [],
            ],
        ];
    }

    // -------------------------------------------------------------------------
    // States
    // -------------------------------------------------------------------------

    /**
     * Alerta pendente — criado mas nenhuma notificação enviada ainda.
     */
    public function pending(): static
    {
        return $this->state(fn () => [
            'notified_at'        => null,
            'dismissed_at'       => null,
            'converted_to_order_id' => null,
            'notification_count' => 0,
        ]);
    }

    /**
     * Alerta notificado — pelo menos uma notificação enviada, cliente ainda não agiu.
     */
    public function notified(): static
    {
        return $this->state(fn () => [
            'notified_at'        => now()->subHours(fake()->numberBetween(1, 23)),
            'dismissed_at'       => null,
            'converted_to_order_id' => null,
            'notification_count' => fake()->numberBetween(1, 3),
        ]);
    }

    /**
     * Alerta dispensado pelo cliente.
     */
    public function dismissed(): static
    {
        return $this->state(fn () => [
            'dismissed_at'  => now()->subHours(fake()->numberBetween(1, 72)),
            'dismiss_reason' => fake()->randomElement([
                'not_mine',
                'already_registered',
                'cancelled_at_marketplace',
                'other',
            ]),
            'converted_to_order_id' => null,
        ]);
    }

    /**
     * Alerta convertido — cliente registrou o pedido manualmente via wizard.
     */
    public function converted(): static
    {
        return $this->state(fn () => [
            'converted_to_order_id' => Order::factory(),
            'dismissed_at'          => null,
        ]);
    }
}
