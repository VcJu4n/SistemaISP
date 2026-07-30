<?php

namespace Database\Factories;

use App\Models\Plan;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<Plan> */
class PlanFactory extends Factory
{
    protected $model = Plan::class;

    public function definition(): array
    {
        $download = fake()->randomElement([10, 20, 30, 50, 100]);

        return [
            'name' => fake()->unique()->numerify('Plan ###'),
            'download_mbps' => $download,
            'upload_mbps' => max(1, (int) ($download / 2)),
            'monthly_price' => fake()->optional()->randomFloat(2, 50, 500),
            'description' => fake()->optional()->sentence(),
            'active' => true,
        ];
    }
}
