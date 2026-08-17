<?php

namespace Database\Factories;

use App\Models\InternetService;
use App\Models\PaymentReceipt;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<PaymentReceipt> */
class PaymentReceiptFactory extends Factory
{
    protected $model = PaymentReceipt::class;

    public function definition(): array
    {
        $service = InternetService::factory()->create();
        $service->load(['client', 'plan']);
        $amount = (float) ($service->plan->monthly_price ?? 100);

        return [
            'receipt_number' => fake()->unique()->numerify('###'),
            'internet_service_id' => $service->id,
            'client_id' => $service->client_id,
            'created_by' => User::factory(),
            'client_name' => $service->client->full_name,
            'client_document' => $service->client->document,
            'client_phone' => $service->client->phone,
            'client_email' => $service->client->email,
            'plan_name' => $service->plan->name,
            'payment_date' => fake()->date(),
            'cutoff_date' => fake()->optional()->date(),
            'billing_period' => fake()->monthName().' '.fake()->year(),
            'concept' => 'Servicio de Internet',
            'observations' => fake()->optional()->sentence(),
            'currency' => 'Bolivianos',
            'subtotal' => $amount,
            'iva_rate' => 0,
            'retention_rate' => 0,
            'iva' => 0,
            'retention' => 0,
            'total_received' => $amount,
            'amount_words' => 'Cien Bolivianos 00/100 (100,00)',
        ];
    }
}
