<?php

namespace Database\Factories;

use App\Models\Client;
use App\Models\InternetService;
use App\Models\Payment;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<Payment> */
class PaymentFactory extends Factory
{
    protected $model = Payment::class;

    public function definition(): array
    {
        $paidAt = fake()->dateTimeBetween('-6 months');

        return [
            'client_id' => Client::factory(),
            'internet_service_id' => InternetService::factory(),
            'user_id' => User::factory(),
            'amount' => fake()->randomFloat(2, 80, 400),
            'paid_at' => $paidAt->format('Y-m-d'),
            'billing_period' => $paidAt->format('Y-m'),
            'payment_method' => fake()->randomElement(Payment::METHODS),
            'observation' => fake()->optional()->sentence(),
            'previous_due_date' => fake()->optional()->date(),
            'next_due_date' => fake()->optional()->date(),
            'duplicate_confirmed' => false,
        ];
    }
}
