<?php

namespace Tests\Feature;

use App\Contracts\MikrotikRouterConnectionTester;
use App\Models\InternetService;
use App\Models\MikrotikRouter;
use App\Models\User;
use App\Services\Mikrotik\RouterOsApiClient;
use App\ValueObjects\MikrotikConnectionResult;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class MikrotikRouterTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Sanctum::actingAs(User::factory()->create());
    }

    public function test_router_can_be_registered_without_exposing_password(): void
    {
        $routerId = $this->postJson('/api/mikrotik-routers', $this->routerPayload())
            ->assertCreated()
            ->assertJsonPath('data.name', 'MikroTik principal')
            ->assertJsonPath('data.ip_address', '192.168.88.1')
            ->assertJsonPath('data.api_port', 8728)
            ->assertJsonPath('data.use_ssl', false)
            ->assertJsonPath('data.active', true)
            ->assertJsonPath('data.connection_status', MikrotikRouter::STATUS_PENDING)
            ->assertJsonMissingPath('data.password')
            ->json('data.id');

        $storedPassword = DB::table('mikrotik_routers')->where('id', $routerId)->value('password');

        $this->assertNotSame('router-secret', $storedPassword);
        $this->assertSame('router-secret', MikrotikRouter::query()->findOrFail($routerId)->password);
    }

    public function test_router_can_be_updated_without_replacing_password(): void
    {
        $router = MikrotikRouter::factory()->create(['password' => 'old-secret']);
        $storedPassword = DB::table('mikrotik_routers')->where('id', $router->id)->value('password');

        $this->putJson("/api/mikrotik-routers/{$router->id}", [
            'name' => 'MikroTik principal',
            'ip_address' => '192.168.88.2',
            'api_port' => 8729,
            'username' => 'api-user',
            'use_ssl' => true,
            'active' => false,
        ])->assertOk()
            ->assertJsonPath('data.active', false)
            ->assertJsonPath('data.connection_status', MikrotikRouter::STATUS_PENDING)
            ->assertJsonMissingPath('data.password');

        $this->assertSame($storedPassword, DB::table('mikrotik_routers')->where('id', $router->id)->value('password'));
        $this->assertSame('old-secret', $router->fresh()->password);
    }

    public function test_router_list_can_be_filtered_and_does_not_expose_password(): void
    {
        MikrotikRouter::factory()->create(['name' => 'MikroTik principal', 'active' => true]);
        MikrotikRouter::factory()->create(['name' => 'Backup router', 'active' => false]);

        $this->getJson('/api/mikrotik-routers?search=principal&active=1')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.name', 'MikroTik principal')
            ->assertJsonMissingPath('data.0.password');
    }

    public function test_router_detail_lists_only_its_clients_and_service_counts(): void
    {
        $router = MikrotikRouter::factory()->create();
        InternetService::factory()->create(['mikrotik_router_id' => $router, 'status' => 'active']);
        InternetService::factory()->create(['mikrotik_router_id' => $router, 'status' => 'suspended']);
        InternetService::factory()->create(['mikrotik_router_id' => MikrotikRouter::factory()]);

        $this->getJson("/api/mikrotik-routers/{$router->id}")
            ->assertOk()
            ->assertJsonCount(2, 'data.services')
            ->assertJsonPath('data.services_count', 2)
            ->assertJsonPath('data.active_services_count', 1)
            ->assertJsonPath('data.suspended_services_count', 1)
            ->assertJsonStructure(['data' => ['services' => [['client', 'plan']]]]);
    }

    public function test_connection_test_marks_router_as_connected(): void
    {
        $router = MikrotikRouter::factory()->create(['connection_status' => MikrotikRouter::STATUS_PENDING]);
        $this->app->bind(MikrotikRouterConnectionTester::class, SuccessfulRouterTester::class);

        $this->postJson("/api/mikrotik-routers/{$router->id}/test-connection")
            ->assertOk()
            ->assertJsonPath('data.connection_status', MikrotikRouter::STATUS_CONNECTED)
            ->assertJsonPath('data.last_error', null)
            ->assertJsonMissingPath('data.password');

        $this->assertNotNull($router->fresh()->last_successful_connection_at);
        $this->assertNotNull($router->fresh()->last_checked_at);
    }

    public function test_connection_test_records_last_error_when_disconnected(): void
    {
        $router = MikrotikRouter::factory()->create([
            'connection_status' => MikrotikRouter::STATUS_CONNECTED,
            'last_successful_connection_at' => now(),
        ]);
        $this->app->bind(MikrotikRouterConnectionTester::class, FailingRouterTester::class);

        $this->postJson("/api/mikrotik-routers/{$router->id}/test-connection")
            ->assertUnprocessable()
            ->assertJsonPath('data.connection_status', MikrotikRouter::STATUS_DISCONNECTED)
            ->assertJsonPath('data.last_error', 'No route to host')
            ->assertJsonMissingPath('data.password');

        $this->assertNotNull($router->fresh()->last_successful_connection_at);
        $this->assertNotNull($router->fresh()->last_checked_at);
    }

    public function test_monitor_checks_all_active_routers_independently(): void
    {
        $connected = MikrotikRouter::factory()->create(['name' => 'Router disponible', 'active' => true]);
        $disconnected = MikrotikRouter::factory()->create(['name' => 'Router sin ruta', 'active' => true]);
        $inactive = MikrotikRouter::factory()->create(['name' => 'Router apagado', 'active' => false]);
        $this->app->bind(MikrotikRouterConnectionTester::class, IndependentRouterTester::class);

        $this->artisan('mikrotik:check-routers')
            ->expectsOutputToContain('Checked: 2, connected: 1, disconnected: 1.')
            ->assertSuccessful();

        $this->assertSame(MikrotikRouter::STATUS_CONNECTED, $connected->fresh()->connection_status);
        $this->assertSame(MikrotikRouter::STATUS_DISCONNECTED, $disconnected->fresh()->connection_status);
        $this->assertSame('No route to host', $disconnected->fresh()->last_error);
        $this->assertNotNull($connected->fresh()->last_checked_at);
        $this->assertNotNull($disconnected->fresh()->last_checked_at);
        $this->assertNull($inactive->fresh()->last_checked_at);
    }

    public function test_router_name_and_endpoint_must_be_unique(): void
    {
        MikrotikRouter::factory()->create([
            'name' => 'MikroTik principal',
            'ip_address' => '192.168.88.1',
            'api_port' => 8728,
        ]);

        $this->postJson('/api/mikrotik-routers', $this->routerPayload())
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['name', 'ip_address']);
    }

    public function test_pppoe_profiles_can_be_read_from_router(): void
    {
        $router = MikrotikRouter::factory()->create();
        $client = new ProfileRouterOsApiClient;
        $this->app->instance(RouterOsApiClient::class, $client);

        $this->getJson("/api/mikrotik-routers/{$router->id}/pppoe-profiles")
            ->assertOk()
            ->assertExactJson(['data' => ['default', 'plan-30m']]);
    }

    private function routerPayload(): array
    {
        return [
            'name' => 'MikroTik principal',
            'ip_address' => '192.168.88.1',
            'api_port' => 8728,
            'username' => 'admin',
            'password' => 'router-secret',
            'use_ssl' => false,
        ];
    }
}

class SuccessfulRouterTester implements MikrotikRouterConnectionTester
{
    public function test(MikrotikRouter $router): MikrotikConnectionResult
    {
        return MikrotikConnectionResult::connected();
    }
}

class FailingRouterTester implements MikrotikRouterConnectionTester
{
    public function test(MikrotikRouter $router): MikrotikConnectionResult
    {
        return MikrotikConnectionResult::disconnected('No route to host');
    }
}

class IndependentRouterTester implements MikrotikRouterConnectionTester
{
    public function test(MikrotikRouter $router): MikrotikConnectionResult
    {
        return $router->name === 'Router disponible'
            ? MikrotikConnectionResult::connected()
            : MikrotikConnectionResult::disconnected('No route to host');
    }
}

class ProfileRouterOsApiClient extends RouterOsApiClient
{
    public function read(MikrotikRouter $router, string $path, array $proplist = []): array
    {
        return [
            ['name' => 'plan-30m'],
            ['name' => 'default'],
            ['name' => 'plan-30m'],
        ];
    }
}
