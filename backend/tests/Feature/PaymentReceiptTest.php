<?php

namespace Tests\Feature;

use App\Models\Client;
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

    public function test_receipt_can_be_created_from_internet_service(): void
    {
        $service = $this->service(['billing_amount' => 120]);

        $this->postJson('/api/receipts', [
            'internet_service_id' => $service->id,
            'payment_date' => '2026-08-11',
            'cutoff_date' => '2026-08-20',
            'billing_period' => 'Agosto 2026',
            'concept' => 'Servicio de Internet',
            'subtotal' => 120,
        ])->assertCreated()
            ->assertJsonPath('data.receipt_number', '001')
            ->assertJsonPath('data.client_name', $service->client->full_name)
            ->assertJsonPath('data.total_received', '120.00');

        $this->assertDatabaseHas('payment_receipts', [
            'receipt_number' => '001',
            'internet_service_id' => $service->id,
            'client_name' => $service->client->full_name,
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

        $this->postJson('/api/receipts', [
            'internet_service_id' => $service->id,
            'payment_date' => '2026-08-11',
            'concept' => 'Servicio de Internet',
            'subtotal' => 120,
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

        $this->postJson('/api/receipts', [
            'internet_service_id' => $service->id,
            'payment_date' => '2026-08-11',
            'concept' => 'Servicio de Internet',
            'subtotal' => 120,
            'send_email' => true,
        ])->assertCreated()
            ->assertJsonPath('email_delivery.status', PaymentReceiptEmailDelivery::STATUS_FAILED);
    }

    public function test_billing_status_marks_paid_service_for_selected_month(): void
    {
        $service = $this->service(['billing_day' => 1, 'cutoff_day' => 5, 'grace_days' => 3, 'billing_amount' => 150]);
        $service->paymentReceipts()->create([
            'receipt_number' => '001',
            'internet_service_id' => $service->id,
            'client_id' => $service->client_id,
            'client_name' => $service->client->full_name,
            'client_document' => $service->client->document,
            'client_phone' => $service->client->phone,
            'client_email' => $service->client->email,
            'plan_name' => $service->plan->name,
            'payment_date' => '2026-08-11',
            'concept' => 'Servicio de Internet',
            'currency' => 'Bolivianos',
            'subtotal' => 150,
            'iva_rate' => 0,
            'retention_rate' => 0,
            'iva' => 0,
            'retention' => 0,
            'total_received' => 150,
            'amount_words' => 'Ciento Cincuenta Bolivianos 00/100 (150,00)',
        ]);

        $this->getJson('/api/billing/status?year=2026&month=8')
            ->assertOk()
            ->assertJsonPath('summary.paid', 1)
            ->assertJsonPath('data.0.status', 'paid')
            ->assertJsonPath('data.0.paid_amount', 150);
    }

    public function test_receipt_cannot_be_created_when_service_billing_is_disabled(): void
    {
        $service = $this->service(['billing_enabled' => false]);

        $this->postJson('/api/receipts', [
            'internet_service_id' => $service->id,
            'payment_date' => '2026-08-11',
            'concept' => 'Servicio de Internet',
            'subtotal' => 100,
        ])->assertUnprocessable()
            ->assertJsonValidationErrors('internet_service_id');
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
}
