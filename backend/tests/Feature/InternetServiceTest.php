<?php

namespace Tests\Feature;

use App\Models\Client;
use App\Models\InternetService;
use App\Models\Plan;
use App\Models\User;
use App\Models\Zone;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class InternetServiceTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Sanctum::actingAs(User::factory()->create());
    }

    public function test_service_can_be_assigned_when_plan_is_available_in_client_zone(): void
    {
        [$client, $plan] = $this->clientAndPlan();

        $this->postJson('/api/services', ['client_id' => $client->id, 'plan_id' => $plan->id, 'installation_date' => '2026-07-30'])
            ->assertCreated()->assertJsonPath('data.status', 'active')->assertJsonPath('data.plan.id', $plan->id);

        $this->assertDatabaseHas('service_histories', ['event_type' => 'created']);
    }

    public function test_client_cannot_have_two_services(): void
    {
        [$client, $plan] = $this->clientAndPlan();
        InternetService::factory()->create(['client_id' => $client, 'plan_id' => $plan]);

        $this->postJson('/api/services', ['client_id' => $client->id, 'plan_id' => $plan->id])
            ->assertUnprocessable()->assertJsonValidationErrors('client_id');
    }

    public function test_plan_must_be_active_and_available_in_client_zone(): void
    {
        $client = Client::factory()->create();
        $otherZone = Zone::factory()->create();
        $plan = Plan::factory()->create(['active' => true]);
        $plan->zones()->attach($otherZone);

        $this->postJson('/api/services', ['client_id' => $client->id, 'plan_id' => $plan->id])
            ->assertUnprocessable()->assertJsonValidationErrors('plan_id');
    }

    public function test_active_service_can_be_suspended_with_reason_and_history(): void
    {
        $service = $this->service();

        $this->postJson("/api/services/{$service->id}/suspend", ['reason' => 'technical'])
            ->assertOk()->assertJsonPath('data.status', 'suspended')->assertJsonPath('data.suspension_reason', 'technical');

        $this->assertDatabaseHas('service_histories', ['internet_service_id' => $service->id, 'event_type' => 'suspended']);
    }

    public function test_other_suspension_reason_requires_notes(): void
    {
        $service = $this->service();
        $this->postJson("/api/services/{$service->id}/suspend", ['reason' => 'other'])
            ->assertUnprocessable()->assertJsonValidationErrors('notes');
    }

    public function test_suspended_service_can_be_reactivated(): void
    {
        $service = $this->service(['status' => 'suspended', 'suspended_at' => now(), 'suspension_reason' => 'debt']);

        $this->postJson("/api/services/{$service->id}/reactivate")
            ->assertOk()->assertJsonPath('data.status', 'active')->assertJsonPath('data.suspended_at', null);

        $this->assertDatabaseHas('service_histories', ['internet_service_id' => $service->id, 'event_type' => 'reactivated']);
    }

    public function test_service_plan_can_be_changed_and_is_recorded(): void
    {
        $service = $this->service();
        $newPlan = Plan::factory()->create(['active' => true]);
        $newPlan->zones()->attach($service->client->zone_id);

        $this->putJson("/api/services/{$service->id}/plan", ['plan_id' => $newPlan->id])
            ->assertOk()->assertJsonPath('data.plan.id', $newPlan->id);

        $this->assertDatabaseHas('service_histories', ['internet_service_id' => $service->id, 'event_type' => 'plan_changed']);
    }

    public function test_services_can_be_searched_and_filtered(): void
    {
        $service = $this->service();
        $this->service(['status' => 'suspended']);

        $this->getJson('/api/services?search='.urlencode($service->client->full_name).'&status=active')
            ->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $service->id);
    }

    public function test_service_detail_contains_technical_history(): void
    {
        $service = $this->service();
        $service->histories()->create(['event_type' => 'created', 'description' => 'Servicio creado.', 'occurred_at' => now()]);

        $this->getJson("/api/services/{$service->id}")->assertOk()->assertJsonCount(1, 'data.histories');
    }

    private function clientAndPlan(): array
    {
        $client = Client::factory()->create();
        $plan = Plan::factory()->create(['active' => true]);
        $plan->zones()->attach($client->zone_id);
        return [$client, $plan];
    }

    private function service(array $attributes = []): InternetService
    {
        [$client, $plan] = $this->clientAndPlan();
        return InternetService::factory()->create([...$attributes, 'client_id' => $client, 'plan_id' => $plan]);
    }
}
