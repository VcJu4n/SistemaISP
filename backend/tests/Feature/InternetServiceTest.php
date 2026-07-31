<?php

namespace Tests\Feature;

use App\Models\Client;
use App\Models\InternetService;
use App\Models\MikrotikOperation;
use App\Models\MikrotikRouter;
use App\Models\Plan;
use App\Models\User;
use App\Models\Zone;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
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

    public function test_mikrotik_service_assignment_creates_pending_access_operation(): void
    {
        [$client, $plan] = $this->clientAndPlan();
        $router = MikrotikRouter::factory()->create();

        $this->postJson('/api/services', [
            'client_id' => $client->id,
            'plan_id' => $plan->id,
            'mikrotik_router_id' => $router->id,
            'mikrotik_control_method' => 'pppoe',
            'pppoe_username' => 'juan.perez',
            'pppoe_password' => 'cliente-secret',
            'pppoe_profile' => 'plan-30m',
            'client_antenna_ip' => '192.168.20.10',
            'client_antenna_mac' => 'AA:BB:CC:DD:EE:01',
            'client_antenna_brand_model' => 'Ubiquiti LiteBeam',
            'client_antenna_device_name' => 'antena-juan',
            'technical_notes' => 'Instalada en techo.',
        ])->assertCreated()
            ->assertJsonPath('data.mikrotik_control_method', 'pppoe')
            ->assertJsonPath('data.client_antenna_ip', '192.168.20.10')
            ->assertJsonMissingPath('data.pppoe_password');

        $this->assertDatabaseHas('mikrotik_operations', [
            'internet_service_id' => 1,
            'mikrotik_router_id' => $router->id,
            'action' => MikrotikOperation::ACTION_CREATE_ACCESS,
            'status' => MikrotikOperation::STATUS_PENDING,
        ]);

        $operation = MikrotikOperation::query()->firstOrFail();
        $this->assertArrayNotHasKey('password', $operation->payload['technical_config']['pppoe']);
        $this->assertTrue($operation->payload['technical_config']['pppoe']['password_configured']);
        $this->assertNotSame('cliente-secret', DB::table('internet_services')->where('id', 1)->value('pppoe_password'));
    }

    public function test_pppoe_technical_config_requires_router_user_password_and_profile(): void
    {
        [$client, $plan] = $this->clientAndPlan();

        $this->postJson('/api/services', [
            'client_id' => $client->id,
            'plan_id' => $plan->id,
            'mikrotik_control_method' => 'pppoe',
        ])->assertUnprocessable()
            ->assertJsonValidationErrors(['mikrotik_router_id', 'pppoe_username', 'pppoe_password', 'pppoe_profile']);
    }

    public function test_simple_queue_service_stores_router_ip_queue_name_and_speed_payload(): void
    {
        [$client, $plan] = $this->clientAndPlan();
        $router = MikrotikRouter::factory()->create(['name' => 'MikroTik principal']);

        $serviceId = $this->postJson('/api/services', [
            'client_id' => $client->id,
            'plan_id' => $plan->id,
            'mikrotik_router_id' => $router->id,
            'mikrotik_control_method' => 'simple_queue',
            'simple_queue_name' => 'cliente-juan',
            'service_ip_address' => '192.168.10.20',
            'service_mac_address' => 'AA:BB:CC:DD:EE:10',
        ])->assertCreated()
            ->assertJsonPath('data.mikrotik_router.name', 'MikroTik principal')
            ->assertJsonPath('data.mikrotik_control_method', 'simple_queue')
            ->assertJsonPath('data.simple_queue_name', 'cliente-juan')
            ->json('data.id');

        $operation = MikrotikOperation::query()->where('internet_service_id', $serviceId)->firstOrFail();
        $this->assertSame("{$plan->download_mbps}M/{$plan->upload_mbps}M", $operation->payload['technical_config']['simple_queue']['max_limit']);
    }

    public function test_service_technical_config_can_be_updated_and_queues_sync_operation(): void
    {
        $service = $this->service();
        $router = MikrotikRouter::factory()->create();

        $this->putJson("/api/services/{$service->id}/technical-config", [
            'mikrotik_router_id' => $router->id,
            'mikrotik_control_method' => 'pppoe',
            'pppoe_username' => 'maria.gomez',
            'pppoe_password' => 'maria-secret',
            'pppoe_profile' => 'plan-30m',
            'client_antenna_ip' => '192.168.20.11',
            'client_antenna_mac' => 'AA:BB:CC:DD:EE:11',
            'client_antenna_brand_model' => 'Ubiquiti LiteBeam',
            'client_antenna_device_name' => 'antena-maria',
            'technical_notes' => 'Configurada desde soporte.',
        ])->assertOk()
            ->assertJsonPath('data.mikrotik_router.id', $router->id)
            ->assertJsonPath('data.mikrotik_control_method', 'pppoe')
            ->assertJsonPath('data.pppoe_username', 'maria.gomez')
            ->assertJsonMissingPath('data.pppoe_password');

        $this->assertDatabaseHas('service_histories', [
            'internet_service_id' => $service->id,
            'event_type' => 'technical_config_updated',
        ]);
        $history = $service->histories()->where('event_type', 'technical_config_updated')->firstOrFail();
        $this->assertArrayNotHasKey('pppoe_password', $history->metadata['before']);
        $this->assertArrayNotHasKey('pppoe_password', $history->metadata['after']);
        $this->assertDatabaseHas('mikrotik_operations', [
            'internet_service_id' => $service->id,
            'mikrotik_router_id' => $router->id,
            'action' => MikrotikOperation::ACTION_CREATE_ACCESS,
            'status' => MikrotikOperation::STATUS_PENDING,
        ]);
    }

    public function test_manual_technical_config_clears_router_and_method_specific_fields(): void
    {
        $service = $this->service([
            'mikrotik_router_id' => MikrotikRouter::factory(),
            'mikrotik_control_method' => 'simple_queue',
            'simple_queue_name' => 'cliente-juan',
            'service_ip_address' => '192.168.10.20',
            'technical_notes' => 'Mantener observacion.',
        ]);

        $this->putJson("/api/services/{$service->id}/technical-config", [
            'mikrotik_control_method' => 'manual',
            'technical_notes' => 'Mantener observacion.',
        ])->assertOk()
            ->assertJsonPath('data.mikrotik_router_id', null)
            ->assertJsonPath('data.mikrotik_control_method', 'manual')
            ->assertJsonPath('data.simple_queue_name', null)
            ->assertJsonPath('data.service_ip_address', null)
            ->assertJsonPath('data.technical_notes', 'Mantener observacion.');
    }

    public function test_mikrotik_identifiers_must_be_unique_between_services(): void
    {
        $router = MikrotikRouter::factory()->create();
        $this->service([
            'mikrotik_router_id' => $router->id,
            'mikrotik_control_method' => 'pppoe',
            'pppoe_username' => 'duplicado',
            'pppoe_password' => 'secret-1',
            'pppoe_profile' => 'plan-10m',
        ]);
        [$client, $plan] = $this->clientAndPlan();

        $this->postJson('/api/services', [
            'client_id' => $client->id,
            'plan_id' => $plan->id,
            'mikrotik_router_id' => $router->id,
            'mikrotik_control_method' => 'pppoe',
            'pppoe_username' => 'duplicado',
            'pppoe_password' => 'secret-2',
            'pppoe_profile' => 'plan-30m',
        ])->assertUnprocessable()
            ->assertJsonValidationErrors('pppoe_username');
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
        $service = $this->service(['mikrotik_control_method' => 'simple_queue', 'simple_queue_name' => 'cliente-juan', 'service_ip_address' => '192.168.10.20']);

        $this->postJson("/api/services/{$service->id}/suspend", ['reason' => 'technical'])
            ->assertOk()->assertJsonPath('data.status', 'suspended')->assertJsonPath('data.suspension_reason', 'technical');

        $this->assertDatabaseHas('service_histories', ['internet_service_id' => $service->id, 'event_type' => 'suspended']);
        $this->assertDatabaseHas('mikrotik_operations', ['internet_service_id' => $service->id, 'action' => MikrotikOperation::ACTION_SUSPEND, 'status' => MikrotikOperation::STATUS_PENDING]);
    }

    public function test_other_suspension_reason_requires_notes(): void
    {
        $service = $this->service();
        $this->postJson("/api/services/{$service->id}/suspend", ['reason' => 'other'])
            ->assertUnprocessable()->assertJsonValidationErrors('notes');
    }

    public function test_suspended_service_can_be_reactivated(): void
    {
        $service = $this->service(['status' => 'suspended', 'suspended_at' => now(), 'suspension_reason' => 'debt', 'mikrotik_control_method' => 'pppoe', 'pppoe_username' => 'maria.gomez', 'pppoe_profile' => 'plan-10m']);

        $this->postJson("/api/services/{$service->id}/reactivate")
            ->assertOk()->assertJsonPath('data.status', 'active')->assertJsonPath('data.suspended_at', null);

        $this->assertDatabaseHas('service_histories', ['internet_service_id' => $service->id, 'event_type' => 'reactivated']);
        $this->assertDatabaseHas('mikrotik_operations', ['internet_service_id' => $service->id, 'action' => MikrotikOperation::ACTION_REACTIVATE, 'status' => MikrotikOperation::STATUS_PENDING]);
    }

    public function test_service_plan_can_be_changed_and_is_recorded(): void
    {
        $service = $this->service(['mikrotik_control_method' => 'simple_queue', 'simple_queue_name' => 'cliente-juan', 'service_ip_address' => '192.168.10.20']);
        $newPlan = Plan::factory()->create(['active' => true]);
        $newPlan->zones()->attach($service->client->zone_id);

        $this->putJson("/api/services/{$service->id}/plan", ['plan_id' => $newPlan->id])
            ->assertOk()->assertJsonPath('data.plan.id', $newPlan->id);

        $this->assertDatabaseHas('service_histories', ['internet_service_id' => $service->id, 'event_type' => 'plan_changed']);
        $this->assertDatabaseHas('mikrotik_operations', ['internet_service_id' => $service->id, 'action' => MikrotikOperation::ACTION_CHANGE_PLAN, 'status' => MikrotikOperation::STATUS_PENDING]);
    }

    public function test_services_can_be_searched_and_filtered(): void
    {
        [$client, $plan] = $this->clientAndPlan();
        $client->update(['full_name' => 'Juan Perez']);
        $service = InternetService::factory()->create(['client_id' => $client, 'plan_id' => $plan]);
        $this->service(['status' => 'suspended']);

        $this->getJson('/api/services?search=juan&status=active')
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
