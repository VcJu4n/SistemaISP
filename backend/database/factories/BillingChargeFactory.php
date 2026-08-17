<?php

namespace Database\Factories;

use App\Models\BillingCharge;
use App\Models\InternetService;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<BillingCharge> */
class BillingChargeFactory extends Factory
{
    protected $model = BillingCharge::class;

    public function definition(): array
    {
        $service = InternetService::factory()->create();
        $service->load(['client', 'plan']);
        $amount = round((float) ($service->monthlyBillingAmount() ?: 100), 2);
        $period = now()->startOfMonth();

        return [
            'internet_service_id' => $service->id,
            'client_id' => $service->client_id,
            'created_by' => User::factory(),
            'period_year' => (int) $period->year,
            'period_month' => (int) $period->month,
            'period_label' => $period->translatedFormat('F Y'),
            'issue_date' => $period->toDateString(),
            'due_date' => $period->copy()->day(1)->toDateString(),
            'cutoff_date' => $period->copy()->day(5)->toDateString(),
            'grace_deadline' => $period->copy()->day(4)->toDateString(),
            'concept' => 'Servicio de Internet',
            'currency' => 'Bolivianos',
            'original_amount' => $amount,
            'paid_amount' => 0,
            'balance' => $amount,
            'status' => BillingCharge::STATUS_PENDING,
        ];
    }
}
