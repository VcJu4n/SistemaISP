<?php

namespace Database\Factories;

use App\Models\Zone;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<Zone> */
class ZoneFactory extends Factory
{
    protected $model = Zone::class;

    public function definition(): array
    {
        return [
            'name' => fake()->unique()->city(),
            'description' => fake()->optional()->sentence(),
            'active' => true,
        ];
    }
}
