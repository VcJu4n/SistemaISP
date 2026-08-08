<?php

namespace Tests\Feature;

use App\Contracts\MikrotikRouterInspector;
use App\Models\Client;
use App\Models\InternetService;
use App\Models\MikrotikImportCandidate;
use App\Models\MikrotikOperation;
use App\Models\MikrotikRouter;
use App\Models\Plan;
use App\Models\User;
use App\Models\Zone;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class MikrotikImportTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Sanctum::actingAs(User::factory()->create());
        $this->app->bind(MikrotikRouterInspector::class, FakeMikrotikRouterInspector::class);
    }

    public function test_control_method_detection_is_read_only_and_updates_router_status(): void
    {
        $router = MikrotikRouter::factory()->create(['connection_status' => MikrotikRouter::STATUS_PENDING]);

        $this->postJson("/api/mikrotik-routers/{$router->id}/detect-control-method")
            ->assertOk()
            ->assertJsonPath('data.primary_method', 'pppoe')
            ->assertJsonPath('data.counts.pppoe', 2)
            ->assertJsonPath('data.counts.simple_queue', 1);

        $router->refresh();
        $this->assertSame(MikrotikRouter::STATUS_CONNECTED, $router->connection_status);
        $this->assertNotNull($router->last_successful_connection_at);
        $this->assertNull($router->last_error);
        $this->assertDatabaseCount('mikrotik_import_candidates', 0);
    }

    public function test_import_sync_stores_candidates_and_marks_existing_service_as_linked(): void
    {
        $router = MikrotikRouter::factory()->create();
        $client = Client::factory()->create();
        $plan = $this->activePlanFor($client->zone_id);
        $service = InternetService::factory()->create([
            'client_id' => $client->id,
            'plan_id' => $plan->id,
            'mikrotik_router_id' => $router->id,
            'mikrotik_control_method' => 'pppoe',
            'pppoe_username' => 'maria.gomez',
            'pppoe_profile' => 'plan-20m',
        ]);

        $this->postJson("/api/mikrotik-routers/{$router->id}/import-candidates/sync")
            ->assertOk()
            ->assertJsonPath('data.synced', 4);

        $this->assertDatabaseHas('mikrotik_import_candidates', [
            'mikrotik_router_id' => $router->id,
            'source_type' => MikrotikImportCandidate::SOURCE_PPPOE,
            'identifier' => 'maria.gomez',
            'status' => MikrotikImportCandidate::STATUS_LINKED,
            'client_id' => $client->id,
            'internet_service_id' => $service->id,
            'access_type' => 'fiber',
        ]);
        $this->assertDatabaseHas('mikrotik_import_candidates', [
            'mikrotik_router_id' => $router->id,
            'source_type' => MikrotikImportCandidate::SOURCE_SIMPLE_QUEUE,
            'identifier' => 'cliente-juan',
            'status' => MikrotikImportCandidate::STATUS_UNLINKED,
            'access_type' => null,
        ]);

        $this->getJson("/api/mikrotik-routers/{$router->id}/import-candidates?all=1")
            ->assertOk()
            ->assertJsonCount(3, 'data');
        $this->getJson("/api/mikrotik-routers/{$router->id}/import-candidates?all=1&access_type=unclassified")
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.identifier', 'cliente-juan');
    }

    public function test_candidate_can_be_linked_to_existing_client_and_creates_imported_service_without_queueing_create_access(): void
    {
        $router = MikrotikRouter::factory()->create();
        $client = Client::factory()->create();
        $plan = $this->activePlanFor($client->zone_id);
        $candidate = MikrotikImportCandidate::factory()->create([
            'mikrotik_router_id' => $router->id,
            'source_type' => MikrotikImportCandidate::SOURCE_SIMPLE_QUEUE,
            'identifier' => 'cliente-juan',
            'ip_address' => '192.168.10.20',
        ]);

        $this->postJson("/api/mikrotik-import-candidates/{$candidate->id}/link", [
            'client_id' => $client->id,
            'plan_id' => $plan->id,
        ])->assertOk()
            ->assertJsonPath('data.status', MikrotikImportCandidate::STATUS_LINKED)
            ->assertJsonPath('data.client.id', $client->id);

        $service = $client->internetService()->firstOrFail();
        $this->assertSame('simple_queue', $service->mikrotik_control_method);
        $this->assertSame('cliente-juan', $service->simple_queue_name);
        $this->assertSame('192.168.10.20', $service->service_ip_address);
        $this->assertSame(0, MikrotikOperation::query()->where('internet_service_id', $service->id)->count());
    }

    public function test_candidate_can_create_client_and_can_be_ignored(): void
    {
        $router = MikrotikRouter::factory()->create();
        $zone = Zone::factory()->create(['active' => true]);
        $plan = $this->activePlanFor($zone->id);
        $candidate = MikrotikImportCandidate::factory()->create([
            'mikrotik_router_id' => $router->id,
            'source_type' => MikrotikImportCandidate::SOURCE_PPPOE,
            'identifier' => 'nuevo.cliente',
            'profile' => 'plan-30m',
        ]);
        $ignored = MikrotikImportCandidate::factory()->create(['mikrotik_router_id' => $router->id]);

        $this->postJson("/api/mikrotik-import-candidates/{$candidate->id}/create-client", [
            'full_name' => 'Nuevo Cliente',
            'document' => '900001',
            'phone' => '70000001',
            'zone_id' => $zone->id,
            'plan_id' => $plan->id,
        ])->assertCreated()
            ->assertJsonPath('data.status', MikrotikImportCandidate::STATUS_LINKED)
            ->assertJsonPath('data.client.full_name', 'Nuevo Cliente');

        $client = Client::query()->where('document', '900001')->firstOrFail();
        $this->assertSame('pppoe', $client->internetService()->firstOrFail()->mikrotik_control_method);
        $this->assertSame('nuevo.cliente', $client->internetService()->firstOrFail()->pppoe_username);

        $this->postJson("/api/mikrotik-import-candidates/{$ignored->id}/ignore")
            ->assertOk()
            ->assertJsonPath('data.status', MikrotikImportCandidate::STATUS_IGNORED);
    }

    private function activePlanFor(int $zoneId): Plan
    {
        $plan = Plan::factory()->create(['active' => true]);
        $plan->zones()->attach($zoneId);

        return $plan;
    }
}

class FakeMikrotikRouterInspector implements MikrotikRouterInspector
{
    public function detectControlMethod(MikrotikRouter $router): array
    {
        return [
            'counts' => ['pppoe' => 2, 'simple_queue' => 1, 'dhcp_mac' => 1, 'hotspot' => 0],
            'detected_methods' => ['pppoe', 'simple_queue', 'dhcp_mac'],
            'primary_method' => 'pppoe',
            'inspected_at' => now()->toISOString(),
        ];
    }

    public function importableRecords(MikrotikRouter $router): array
    {
        return [
            ['source_type' => 'pppoe', 'access_type' => 'fiber', 'external_id' => '*1', 'identifier' => 'maria.gomez', 'display_name' => 'maria.gomez', 'profile' => 'plan-20m', 'raw_payload' => ['name' => 'maria.gomez']],
            ['source_type' => 'pppoe', 'access_type' => 'fiber', 'external_id' => '*2', 'identifier' => 'juan.perez', 'display_name' => 'juan.perez', 'profile' => 'plan-30m', 'raw_payload' => ['name' => 'juan.perez']],
            ['source_type' => 'simple_queue', 'external_id' => '*3', 'identifier' => 'cliente-juan', 'display_name' => 'cliente-juan', 'ip_address' => '192.168.10.20', 'rate_limit' => '15M/30M', 'raw_payload' => ['name' => 'cliente-juan']],
            ['source_type' => 'dhcp_mac', 'access_type' => 'antenna', 'external_id' => '*4', 'identifier' => 'AA:BB:CC:DD:EE:01', 'display_name' => 'antena-uno', 'ip_address' => '192.168.20.10', 'mac_address' => 'AA:BB:CC:DD:EE:01', 'raw_payload' => ['mac-address' => 'AA:BB:CC:DD:EE:01']],
        ];
    }
}
