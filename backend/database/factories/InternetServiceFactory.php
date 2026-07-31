<?php

namespace Database\Factories;

use App\Models\Client;
use App\Models\InternetService;
use App\Models\Plan;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<InternetService> */
class InternetServiceFactory extends Factory
{
    protected $model = InternetService::class;

    public function definition(): array
    {
        return [
            'client_id' => Client::factory(),
            'plan_id' => Plan::factory(),
            'status' => 'active',
            'installation_date' => fake()->date(),
            'notes' => fake()->optional()->sentence(),
            'mikrotik_control_method' => 'manual',
        ];
    }
}
