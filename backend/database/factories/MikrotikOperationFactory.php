<?php

namespace Database\Factories;

use App\Models\InternetService;
use App\Models\MikrotikOperation;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<MikrotikOperation> */
class MikrotikOperationFactory extends Factory
{
    protected $model = MikrotikOperation::class;

    public function definition(): array
    {
        return [
            'internet_service_id' => InternetService::factory(),
            'mikrotik_router_id' => null,
            'action' => fake()->randomElement(MikrotikOperation::ACTIONS),
            'status' => MikrotikOperation::STATUS_PENDING,
            'attempts' => 0,
            'payload' => ['source' => 'factory'],
        ];
    }
}
