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
            'next_due_date' => fake()->dateTimeBetween('now', '+1 month')->format('Y-m-d'),
            'notes' => fake()->optional()->sentence(),
            'mikrotik_control_method' => 'manual',
        ];
    }
}
