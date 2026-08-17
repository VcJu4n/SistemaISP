<?php

namespace Tests\Feature;

use App\Models\Client;
use App\Models\BillingCharge;
use App\Models\InternetService;
use App\Models\PaymentReceiptEmailDelivery;
use App\Models\Plan;
use App\Models\User;
use App\Models\Zone;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class PaymentReceiptTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Sanctum::actingAs(User::factory()->create());
    }

    public function test_receipt_can_be_created_from_billing_charge(): void
    {
        $service = $this->service(['billing_amount' => 120]);
        $charge = $this->generateCharge($service, 2026, 8);

        $this->postJson('/api/payments', [
            'billing_charge_id' => $charge->id,
            'payment_date' => '2026-08-11',
        ])->assertCreated()
            ->assertJsonPath('data.receipt_number', '001')
            ->assertJsonPath('data.client_name', $service->client->full_name)
            ->assertJsonPath('data.billing_charge_id', $charge->id)
            ->assertJsonPath('data.total_received', '120.00');

        $this->assertDatabaseHas('payment_receipts', [
            'receipt_number' => '001',
            'billing_charge_id' => $charge->id,
            'internet_service_id' => $service->id,
            'client_name' => $service->client->full_name,
        ]);
        $this->assertDatabaseHas('billing_charges', [
            'id' => $charge->id,
            'status' => BillingCharge::STATUS_PAID,
            'balance' => 0,
        ]);
        $this->assertDatabaseHas('service_histories', [
            'internet_service_id' => $service->id,
            'event_type' => 'payment_received',
        ]);
    }

    public function test_receipt_can_be_created_and_sent_by_email(): void
    {
        Mail::fake();
        $service = $this->service(['billing_amount' => 120]);
        $charge = $this->generateCharge($service, 2026, 8);

        $this->postJson('/api/payments', [
            'billing_charge_id' => $charge->id,
            'payment_date' => '2026-08-11',
            'send_email' => true,
        ])->assertCreated()
            ->assertJsonPath('email_delivery.status', PaymentReceiptEmailDelivery::STATUS_SENT)
            ->assertJsonPath('data.latest_email_delivery.status', PaymentReceiptEmailDelivery::STATUS_SENT);

        $this->assertDatabaseHas('payment_receipt_email_deliveries', [
            'recipient_email' => $service->client->email,
            'status' => PaymentReceiptEmailDelivery::STATUS_SENT,
        ]);
    }

    public function test_receipt_email_send_failure_is_recorded_when_client_has_no_email(): void
    {
        $service = $this->service(['billing_amount' => 120]);
        $service->client->update(['email' => null]);
        $charge = $this->generateCharge($service, 2026, 8);

        $this->postJson('/api/payments', [
            'billing_charge_id' => $charge->id,
            'payment_date' => '2026-08-11',
            'send_email' => true,
        ])->assertCreated()
            ->assertJsonPath('email_delivery.status', PaymentReceiptEmailDelivery::STATUS_FAILED);
    }

    public function test_billing_status_marks_paid_service_for_selected_month(): void
    {
        $service = $this->service(['billing_day' => 1, 'cutoff_day' => 5, 'grace_days' => 3, 'billing_amount' => 150]);

        $this->postJson('/api/billing/charges/generate', [
            'year' => 2026,
            'month' => 8,
        ])->assertCreated()
            ->assertJsonPath('generation.created', 1);

        $charge = BillingCharge::query()->where('internet_service_id', $service->id)->firstOrFail();

        $this->postJson('/api/payments', [
            'billing_charge_id' => $charge->id,
            'payment_date' => '2026-08-11',
        ])->assertCreated();

        $this->getJson('/api/billing/status?year=2026&month=8')
            ->assertOk()
            ->assertJsonPath('summary.paid', 1)
            ->assertJsonPath('data.0.status', 'paid')
            ->assertJsonPath('data.0.paid_amount', 150);
    }

    public function test_monthly_charges_are_generated_once_per_service_and_period(): void
    {
        $service = $this->service(['billing_day' => 10, 'cutoff_day' => 15, 'grace_days' => 4, 'billing_amount' => 180]);

        $this->postJson('/api/billing/charges/generate', [
            'year' => 2026,
            'month' => 8,
        ])->assertCreated()
            ->assertJsonPath('generation.created', 1)
            ->assertJsonPath('data.0.client_name', $service->client->full_name)
            ->assertJsonPath('data.0.balance', 180);

        $this->postJson('/api/billing/charges/generate', [
            'year' => 2026,
            'month' => 8,
        ])->assertCreated()
            ->assertJsonPath('generation.created', 0)
            ->assertJsonPath('generation.existing', 1);

        $this->assertDatabaseCount('billing_charges', 1);
    }

    public function test_receipt_requires_a_generated_billing_charge(): void
    {
        $this->postJson('/api/payments', [
            'payment_date' => '2026-08-11',
        ])->assertUnprocessable()
            ->assertJsonValidationErrors('billing_charge_id');
    }

    public function test_disabled_services_do_not_generate_monthly_charges(): void
    {
        $service = $this->service(['billing_enabled' => false]);

        $this->postJson('/api/billing/charges/generate', [
            'year' => 2026,
            'month' => 8,
        ])->assertCreated()
            ->assertJsonPath('generation.created', 0);

        $this->assertDatabaseMissing('billing_charges', [
            'internet_service_id' => $service->id,
            'period_year' => 2026,
            'period_month' => 8,
        ]);
    }

    public function test_service_billing_configuration_can_be_updated(): void
    {
        $service = $this->service();

        $this->putJson("/api/billing/services/{$service->id}", [
            'billing_day' => 10,
            'cutoff_day' => 20,
            'grace_days' => 5,
            'billing_amount' => 180,
            'billing_enabled' => true,
        ])->assertOk()
            ->assertJsonPath('data.billing_day', 10)
            ->assertJsonPath('data.billing_amount', '180.00');
    }

    public function test_future_billing_period_stays_pending_when_unpaid(): void
    {
        $service = $this->service(['billing_day' => 1, 'cutoff_day' => 5, 'grace_days' => 3, 'billing_amount' => 150]);
        $future = now()->addYear();

        $this->postJson('/api/billing/charges/generate', [
            'year' => $future->year,
            'month' => $future->month,
        ])->assertCreated();

        $this->getJson("/api/billing/status?year={$future->year}&month={$future->month}")
            ->assertOk()
            ->assertJsonPath('summary.pending', 1)
            ->assertJsonPath('data.0.service_id', $service->id)
            ->assertJsonPath('data.0.status', 'pending');
    }

    private function service(array $attributes = []): InternetService
    {
        $zone = Zone::factory()->create();
        $client = Client::factory()->create(['zone_id' => $zone->id]);
        $plan = Plan::factory()->create(['active' => true, 'monthly_price' => 100]);
        $plan->zones()->attach($zone);

        return InternetService::factory()->create([...$attributes, 'client_id' => $client->id, 'plan_id' => $plan->id]);
    }

    private function generateCharge(InternetService $service, int $year, int $month): BillingCharge
    {
        $this->postJson('/api/billing/charges/generate', [
            'year' => $year,
            'month' => $month,
        ])->assertCreated();

        return BillingCharge::query()
            ->where('internet_service_id', $service->id)
            ->where('period_year', $year)
            ->where('period_month', $month)
            ->firstOrFail();
    }
}
