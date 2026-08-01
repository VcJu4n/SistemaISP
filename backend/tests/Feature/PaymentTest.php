<?php

namespace Tests\Feature;

use App\Models\Client;
use App\Models\InternetService;
use App\Models\Payment;
use App\Models\Plan;
use App\Models\User;
use App\Models\Zone;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class PaymentTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();
        $this->user = User::factory()->create(['name' => 'Operador Caja']);
        Sanctum::actingAs($this->user);
    }

    public function test_payment_can_be_registered_and_moves_due_date_to_next_monthly_cycle(): void
    {
        $service = $this->service(['next_due_date' => '2026-08-10']);

        $this->postJson('/api/payments', [
            'client_id' => $service->client_id,
            'amount' => 150,
            'paid_at' => '2026-08-03',
            'payment_method' => Payment::METHOD_CASH,
            'observation' => 'Pago en oficina.',
        ])->assertCreated()
            ->assertJsonPath('data.amount', '150.00')
            ->assertJsonPath('data.billing_period', '2026-08')
            ->assertJsonPath('data.next_due_date', '2026-09-10')
            ->assertJsonPath('data.user.name', 'Operador Caja');

        $this->assertDatabaseHas('payments', [
            'client_id' => $service->client_id,
            'internet_service_id' => $service->id,
            'user_id' => $this->user->id,
            'billing_period' => '2026-08',
            'payment_method' => Payment::METHOD_CASH,
            'previous_due_date' => '2026-08-10',
            'next_due_date' => '2026-09-10',
        ]);
        $this->assertDatabaseHas('internet_services', ['id' => $service->id, 'next_due_date' => '2026-09-10']);
        $this->assertDatabaseHas('service_histories', [
            'internet_service_id' => $service->id,
            'user_id' => $this->user->id,
            'event_type' => 'payment_registered',
        ]);
    }

    public function test_duplicate_monthly_payment_requires_explicit_confirmation(): void
    {
        $service = $this->service(['next_due_date' => '2026-08-10']);
        Payment::factory()->create([
            'client_id' => $service->client_id,
            'internet_service_id' => $service->id,
            'user_id' => $this->user->id,
            'paid_at' => '2026-08-01',
            'billing_period' => '2026-08',
            'amount' => 150,
        ]);

        $payload = [
            'client_id' => $service->client_id,
            'amount' => 150,
            'paid_at' => '2026-08-20',
            'payment_method' => Payment::METHOD_TRANSFER,
        ];

        $this->postJson('/api/payments', $payload)
            ->assertStatus(409)
            ->assertJsonPath('code', 'duplicate_payment');

        $this->postJson('/api/payments', [...$payload, 'confirm_duplicate' => true])
            ->assertCreated()
            ->assertJsonPath('data.duplicate_confirmed', true);

        $this->assertSame(2, Payment::query()->where('internet_service_id', $service->id)->where('billing_period', '2026-08')->count());
    }

    public function test_payment_can_reactivate_service_suspended_by_debt(): void
    {
        $service = $this->service([
            'status' => 'suspended',
            'suspended_at' => now(),
            'suspension_reason' => 'debt',
            'next_due_date' => '2026-08-10',
        ]);

        $this->postJson('/api/payments', [
            'client_id' => $service->client_id,
            'amount' => 150,
            'paid_at' => '2026-08-04',
            'payment_method' => Payment::METHOD_CASH,
            'reactivate_if_suspended' => true,
        ])->assertCreated();

        $this->assertDatabaseHas('internet_services', [
            'id' => $service->id,
            'status' => 'active',
            'suspension_reason' => null,
        ]);
        $this->assertDatabaseHas('service_histories', [
            'internet_service_id' => $service->id,
            'user_id' => $this->user->id,
            'event_type' => 'reactivated',
        ]);
    }

    public function test_client_payment_history_is_filtered_and_sorted_descending(): void
    {
        $service = $this->service();
        Payment::factory()->create([
            'client_id' => $service->client_id,
            'internet_service_id' => $service->id,
            'paid_at' => '2026-06-01',
            'billing_period' => '2026-06',
        ]);
        Payment::factory()->create([
            'client_id' => $service->client_id,
            'internet_service_id' => $service->id,
            'paid_at' => '2026-07-01',
            'billing_period' => '2026-07',
        ]);
        Payment::factory()->create([
            'client_id' => $service->client_id,
            'internet_service_id' => $service->id,
            'paid_at' => '2026-08-01',
            'billing_period' => '2026-08',
        ]);

        $this->getJson("/api/clients/{$service->client_id}/payments?date_from=2026-07-01&date_to=2026-08-31")
            ->assertOk()
            ->assertJsonCount(2, 'data')
            ->assertJsonPath('data.0.billing_period', '2026-08')
            ->assertJsonPath('data.1.billing_period', '2026-07');
    }

    public function test_global_payments_can_be_filtered_by_client_zone_and_date(): void
    {
        $north = Zone::factory()->create(['name' => 'Norte']);
        $south = Zone::factory()->create(['name' => 'Sur']);
        $northService = $this->service([], $north);
        $southService = $this->service([], $south);

        Payment::factory()->create([
            'client_id' => $northService->client_id,
            'internet_service_id' => $northService->id,
            'paid_at' => '2026-08-01',
            'billing_period' => '2026-08',
            'amount' => 120,
        ]);
        Payment::factory()->create([
            'client_id' => $southService->client_id,
            'internet_service_id' => $southService->id,
            'paid_at' => '2026-08-02',
            'billing_period' => '2026-08',
            'amount' => 200,
        ]);

        $this->getJson("/api/payments?zone_id={$north->id}&date_from=2026-08-01&date_to=2026-08-31")
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.client.zone.name', 'Norte');
    }

    public function test_due_date_can_be_updated_manually_with_reason_and_operator(): void
    {
        $service = $this->service(['next_due_date' => '2026-08-10']);

        $this->putJson("/api/services/{$service->id}/due-date", [
            'next_due_date' => '2026-08-25',
            'reason' => 'Convenio de pago aprobado.',
        ])->assertOk()
            ->assertJsonPath('data.next_due_date', '2026-08-25');

        $this->assertDatabaseHas('internet_services', ['id' => $service->id, 'next_due_date' => '2026-08-25']);
        $this->assertDatabaseHas('service_histories', [
            'internet_service_id' => $service->id,
            'user_id' => $this->user->id,
            'event_type' => 'due_date_updated',
        ]);
    }

    private function service(array $attributes = [], ?Zone $zone = null): InternetService
    {
        $zone ??= Zone::factory()->create();
        $client = Client::factory()->create(['zone_id' => $zone->id]);
        $plan = Plan::factory()->create(['monthly_price' => 150, 'active' => true]);
        $plan->zones()->attach($zone);

        return InternetService::factory()->create([
            ...$attributes,
            'client_id' => $client->id,
            'plan_id' => $plan->id,
        ]);
    }
}
