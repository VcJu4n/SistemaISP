<?php

namespace Database\Factories;

use App\Models\Client;
use App\Models\Zone;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<Client> */
class ClientFactory extends Factory
{
    protected $model = Client::class;

    public function definition(): array
    {
        return [
            'full_name' => fake()->name(),
            'document' => fake()->unique()->numerify('########'),
            'phone' => fake()->numerify('7#######'),
            'email' => fake()->unique()->safeEmail(),
            'address' => fake()->address(),
            'zone_id' => Zone::factory(),
            'installation_date' => fake()->date(),
            'status' => 'active',
        ];
    }
}
