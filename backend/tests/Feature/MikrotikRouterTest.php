<?php

namespace Tests\Feature;

use App\Contracts\MikrotikRouterConnectionTester;
use App\Models\MikrotikRouter;
use App\Models\User;
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
