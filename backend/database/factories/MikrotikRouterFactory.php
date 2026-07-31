<?php

namespace Database\Factories;

use App\Models\MikrotikRouter;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<MikrotikRouter> */
class MikrotikRouterFactory extends Factory
{
    protected $model = MikrotikRouter::class;

    public function definition(): array
    {
        return [
            'name' => fake()->unique()->words(2, true),
            'ip_address' => fake()->unique()->localIpv4(),
            'api_port' => 8728,
            'username' => 'admin',
            'password' => 'secret-password',
            'use_ssl' => false,
            'active' => true,
            'connection_status' => MikrotikRouter::STATUS_PENDING,
        ];
    }
}
