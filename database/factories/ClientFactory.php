<?php

namespace Database\Factories;

use App\Models\Client;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * ClientFactory
 * MUL-029: criado para testes do BlingRelayController.
 */
class ClientFactory extends Factory
{
    protected $model = Client::class;

    public function definition(): array
    {
        return [
            'user_id'      => User::factory(),
            'company_name' => $this->faker->company(),
            'document'     => $this->faker->numerify('##########'),
            'is_active'    => true,
        ];
    }
}
