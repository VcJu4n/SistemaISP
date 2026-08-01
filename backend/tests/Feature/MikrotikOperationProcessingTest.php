<?php

namespace Tests\Feature;

use App\Contracts\MikrotikOperationExecutor;
use App\Models\InternetService;
use App\Models\MikrotikOperation;
use App\Models\MikrotikRouter;
use App\Services\Mikrotik\MikrotikOperationProcessor;
use App\Services\Mikrotik\RouterOsApiClient;
use App\Services\Mikrotik\RouterOsMikrotikOperationExecutor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use RuntimeException;
use Tests\TestCase;

class MikrotikOperationProcessingTest extends TestCase
{
    use RefreshDatabase;

    public function test_pending_operation_is_marked_synced_when_executor_succeeds(): void
    {
        $operation = MikrotikOperation::factory()->create();

        $this->app->bind(MikrotikOperationExecutor::class, SuccessfulExecutor::class);

        $summary = $this->app->make(MikrotikOperationProcessor::class)->process();

        $this->assertSame(['processed' => 1, 'synced' => 1, 'failed' => 0, 'skipped' => 0], $summary);
        $this->assertDatabaseHas('mikrotik_operations', [
            'id' => $operation->id,
            'status' => MikrotikOperation::STATUS_SYNCED,
            'attempts' => 1,
            'last_error' => null,
        ]);
        $this->assertNotNull($operation->fresh()->synced_at);
    }

    public function test_pending_operation_is_marked_failed_when_executor_fails(): void
    {
        $operation = MikrotikOperation::factory()->create();

        $this->app->bind(MikrotikOperationExecutor::class, FailingExecutor::class);

        $summary = $this->app->make(MikrotikOperationProcessor::class)->process();

        $this->assertSame(['processed' => 1, 'synced' => 0, 'failed' => 1, 'skipped' => 0], $summary);
        $this->assertDatabaseHas('mikrotik_operations', [
            'id' => $operation->id,
            'status' => MikrotikOperation::STATUS_FAILED,
            'attempts' => 1,
            'last_error' => 'Router disconnected',
        ]);
        $this->assertNotNull($operation->fresh()->last_attempt_at);
    }

    public function test_failed_operations_are_only_reprocessed_when_requested_and_attempts_remain(): void
    {
        config(['mikrotik.operations.max_attempts' => 2]);

        $retryable = MikrotikOperation::factory()->create([
            'status' => MikrotikOperation::STATUS_FAILED,
            'attempts' => 1,
            'last_error' => 'Previous failure',
        ]);
        $exhausted = MikrotikOperation::factory()->create([
            'status' => MikrotikOperation::STATUS_FAILED,
            'attempts' => 2,
            'last_error' => 'Final failure',
        ]);

        $this->app->bind(MikrotikOperationExecutor::class, SuccessfulExecutor::class);

        $withoutRetry = $this->app->make(MikrotikOperationProcessor::class)->process();
        $this->assertSame(['processed' => 0, 'synced' => 0, 'failed' => 0, 'skipped' => 0], $withoutRetry);

        $withRetry = $this->app->make(MikrotikOperationProcessor::class)->process(retryFailed: true);
        $this->assertSame(['processed' => 1, 'synced' => 1, 'failed' => 0, 'skipped' => 0], $withRetry);

        $this->assertSame(MikrotikOperation::STATUS_SYNCED, $retryable->fresh()->status);
        $this->assertSame(MikrotikOperation::STATUS_FAILED, $exhausted->fresh()->status);
    }

    public function test_stale_processing_operations_are_reclaimed(): void
    {
        config(['mikrotik.operations.stale_processing_minutes' => 10]);

        $operation = MikrotikOperation::factory()->create([
            'status' => MikrotikOperation::STATUS_PROCESSING,
            'attempts' => 1,
            'last_attempt_at' => now()->subMinutes(15),
        ]);

        $this->app->bind(MikrotikOperationExecutor::class, SuccessfulExecutor::class);

        $summary = $this->app->make(MikrotikOperationProcessor::class)->process();

        $this->assertSame(['processed' => 1, 'synced' => 1, 'failed' => 0, 'skipped' => 0], $summary);
        $this->assertSame(MikrotikOperation::STATUS_SYNCED, $operation->fresh()->status);
        $this->assertSame(2, $operation->fresh()->attempts);
    }

    public function test_fresh_processing_operations_are_not_reclaimed(): void
    {
        config(['mikrotik.operations.stale_processing_minutes' => 10]);

        $operation = MikrotikOperation::factory()->create([
            'status' => MikrotikOperation::STATUS_PROCESSING,
            'attempts' => 1,
            'last_attempt_at' => now()->subMinutes(2),
        ]);

        $this->app->bind(MikrotikOperationExecutor::class, SuccessfulExecutor::class);

        $summary = $this->app->make(MikrotikOperationProcessor::class)->process();

        $this->assertSame(['processed' => 0, 'synced' => 0, 'failed' => 0, 'skipped' => 0], $summary);
        $this->assertSame(MikrotikOperation::STATUS_PROCESSING, $operation->fresh()->status);
        $this->assertSame(1, $operation->fresh()->attempts);
    }

    public function test_command_processes_pending_operations(): void
    {
        MikrotikOperation::factory()->count(2)->create();

        $this->app->bind(MikrotikOperationExecutor::class, SuccessfulExecutor::class);

        $this->artisan('mikrotik:process-pending --limit=1')
            ->assertSuccessful();

        $this->assertSame(1, MikrotikOperation::query()->where('status', MikrotikOperation::STATUS_SYNCED)->count());
        $this->assertSame(1, MikrotikOperation::query()->where('status', MikrotikOperation::STATUS_PENDING)->count());
    }

    public function test_routeros_executor_creates_pppoe_secret_command(): void
    {
        $router = MikrotikRouter::factory()->create();
        $service = InternetService::factory()->create([
            'mikrotik_router_id' => $router->id,
            'mikrotik_control_method' => 'pppoe',
            'pppoe_username' => 'juan.perez',
            'pppoe_password' => 'cliente-secret',
            'pppoe_profile' => 'plan-30m',
        ]);
        $operation = MikrotikOperation::factory()->create([
            'internet_service_id' => $service->id,
            'mikrotik_router_id' => $router->id,
            'action' => MikrotikOperation::ACTION_CREATE_ACCESS,
        ]);
        $client = new RecordingRouterOsApiClient;

        (new RouterOsMikrotikOperationExecutor($client))->execute($operation);

        $this->assertSame($router->id, $client->router?->id);
        $this->assertSame([
            '/ppp/secret/add',
            '=name=juan.perez',
            '=password=cliente-secret',
            '=profile=plan-30m',
            '=disabled=no',
            "=comment=SistemaISP servicio #{$service->id}",
        ], $client->command);
    }

    public function test_routeros_executor_creates_simple_queue_command(): void
    {
        $router = MikrotikRouter::factory()->create();
        $service = InternetService::factory()->create([
            'mikrotik_router_id' => $router->id,
            'mikrotik_control_method' => 'simple_queue',
            'simple_queue_name' => 'cliente-juan',
            'service_ip_address' => '192.168.10.20',
        ]);
        $operation = MikrotikOperation::factory()->create([
            'internet_service_id' => $service->id,
            'mikrotik_router_id' => $router->id,
            'action' => MikrotikOperation::ACTION_CREATE_ACCESS,
        ]);
        $client = new RecordingRouterOsApiClient;

        (new RouterOsMikrotikOperationExecutor($client))->execute($operation);

        $this->assertSame([
            '/queue/simple/add',
            '=name=cliente-juan',
            '=target=192.168.10.20/32',
            "=max-limit={$service->plan->upload_mbps}M/{$service->plan->download_mbps}M",
            '=disabled=no',
            "=comment=SistemaISP servicio #{$service->id}",
        ], $client->command);
    }

    public function test_routeros_executor_suspends_and_reactivates_pppoe_secret(): void
    {
        $router = MikrotikRouter::factory()->create();
        $service = InternetService::factory()->create([
            'mikrotik_router_id' => $router->id,
            'mikrotik_control_method' => 'pppoe',
            'pppoe_username' => 'juan.perez',
            'pppoe_password' => 'cliente-secret',
            'pppoe_profile' => 'plan-30m',
        ]);
        $client = new RecordingRouterOsApiClient;
        $client->ids['/ppp/secret|name|juan.perez'] = '*ppp1';

        (new RouterOsMikrotikOperationExecutor($client))->execute(MikrotikOperation::factory()->create([
            'internet_service_id' => $service->id,
            'mikrotik_router_id' => $router->id,
            'action' => MikrotikOperation::ACTION_SUSPEND,
        ]));
        (new RouterOsMikrotikOperationExecutor($client))->execute(MikrotikOperation::factory()->create([
            'internet_service_id' => $service->id,
            'mikrotik_router_id' => $router->id,
            'action' => MikrotikOperation::ACTION_REACTIVATE,
        ]));

        $this->assertSame('/ppp/secret|name|juan.perez', $client->lookups[0]);
        $this->assertSame([
            '/ppp/secret/set',
            '=.id=*ppp1',
            '=disabled=yes',
            "=comment=SistemaISP servicio #{$service->id}",
        ], $client->commands[0]);
        $this->assertSame([
            '/ppp/secret/set',
            '=.id=*ppp1',
            '=disabled=no',
            "=comment=SistemaISP servicio #{$service->id}",
        ], $client->commands[1]);
    }

    public function test_routeros_executor_disconnects_active_pppoe_session_when_suspending(): void
    {
        $router = MikrotikRouter::factory()->create();
        $service = InternetService::factory()->create([
            'mikrotik_router_id' => $router->id,
            'mikrotik_control_method' => 'pppoe',
            'pppoe_username' => 'cliente.activo',
            'pppoe_password' => 'cliente-secret',
            'pppoe_profile' => 'plan-30m',
        ]);
        $client = new RecordingRouterOsApiClient;
        $client->ids['/ppp/secret|name|cliente.activo'] = '*secret1';
        $client->ids['/ppp/active|name|cliente.activo'] = '*active1';

        (new RouterOsMikrotikOperationExecutor($client))->execute(MikrotikOperation::factory()->create([
            'internet_service_id' => $service->id,
            'mikrotik_router_id' => $router->id,
            'action' => MikrotikOperation::ACTION_SUSPEND,
        ]));

        $this->assertSame([
            '/ppp/active/remove',
            '=.id=*active1',
        ], $client->commands[1]);
    }

    public function test_routeros_executor_suspends_and_reactivates_simple_queue(): void
    {
        $router = MikrotikRouter::factory()->create();
        $service = InternetService::factory()->create([
            'mikrotik_router_id' => $router->id,
            'mikrotik_control_method' => 'simple_queue',
            'simple_queue_name' => 'cliente-juan',
            'service_ip_address' => '192.168.10.20',
        ]);
        $client = new RecordingRouterOsApiClient;
        $client->ids['/queue/simple|name|cliente-juan'] = '*queue1';

        (new RouterOsMikrotikOperationExecutor($client))->execute(MikrotikOperation::factory()->create([
            'internet_service_id' => $service->id,
            'mikrotik_router_id' => $router->id,
            'action' => MikrotikOperation::ACTION_SUSPEND,
        ]));
        (new RouterOsMikrotikOperationExecutor($client))->execute(MikrotikOperation::factory()->create([
            'internet_service_id' => $service->id,
            'mikrotik_router_id' => $router->id,
            'action' => MikrotikOperation::ACTION_REACTIVATE,
        ]));

        $this->assertSame([
            '/queue/simple/set',
            '=.id=*queue1',
            '=disabled=yes',
            "=comment=SistemaISP servicio #{$service->id}",
        ], $client->commands[0]);
        $this->assertSame([
            '/queue/simple/set',
            '=.id=*queue1',
            '=disabled=no',
            "=comment=SistemaISP servicio #{$service->id}",
        ], $client->commands[1]);
    }

    public function test_routeros_executor_changes_pppoe_profile_and_simple_queue_speed(): void
    {
        $router = MikrotikRouter::factory()->create();
        $pppoe = InternetService::factory()->create([
            'mikrotik_router_id' => $router->id,
            'mikrotik_control_method' => 'pppoe',
            'pppoe_username' => 'maria.gomez',
            'pppoe_password' => 'cliente-secret',
            'pppoe_profile' => 'plan-30m',
        ]);
        $queue = InternetService::factory()->create([
            'mikrotik_router_id' => $router->id,
            'mikrotik_control_method' => 'simple_queue',
            'simple_queue_name' => 'cliente-maria',
            'service_ip_address' => '192.168.10.21',
        ]);
        $client = new RecordingRouterOsApiClient;
        $client->ids['/ppp/secret|name|maria.gomez'] = '*ppp2';
        $client->ids['/queue/simple|name|cliente-maria'] = '*queue2';

        (new RouterOsMikrotikOperationExecutor($client))->execute(MikrotikOperation::factory()->create([
            'internet_service_id' => $pppoe->id,
            'mikrotik_router_id' => $router->id,
            'action' => MikrotikOperation::ACTION_CHANGE_PLAN,
        ]));
        (new RouterOsMikrotikOperationExecutor($client))->execute(MikrotikOperation::factory()->create([
            'internet_service_id' => $queue->id,
            'mikrotik_router_id' => $router->id,
            'action' => MikrotikOperation::ACTION_CHANGE_PLAN,
        ]));

        $this->assertSame([
            '/ppp/secret/set',
            '=.id=*ppp2',
            '=profile=plan-30m',
            "=comment=SistemaISP servicio #{$pppoe->id}",
        ], $client->commands[0]);
        $this->assertSame([
            '/queue/simple/set',
            '=.id=*queue2',
            "=max-limit={$queue->plan->upload_mbps}M/{$queue->plan->download_mbps}M",
            "=comment=SistemaISP servicio #{$queue->id}",
        ], $client->commands[1]);
    }
}

class SuccessfulExecutor implements MikrotikOperationExecutor
{
    public function execute(MikrotikOperation $operation): void
    {
        //
    }
}

class FailingExecutor implements MikrotikOperationExecutor
{
    public function execute(MikrotikOperation $operation): void
    {
        throw new RuntimeException('Router disconnected');
    }
}

class RecordingRouterOsApiClient extends RouterOsApiClient
{
    public ?MikrotikRouter $router = null;

    /** @var list<string> */
    public array $command = [];

    /** @var list<list<string>> */
    public array $commands = [];

    /** @var array<string, string> */
    public array $ids = [];

    /** @var list<string> */
    public array $lookups = [];

    public function executeCommand(MikrotikRouter $router, array $words): void
    {
        $this->router = $router;
        $this->command = $words;
        $this->commands[] = $words;
    }

    public function findOneId(MikrotikRouter $router, string $path, string $field, string $value): string
    {
        $this->router = $router;
        $key = "{$path}|{$field}|{$value}";
        $this->lookups[] = $key;

        return $this->ids[$key] ?? '*1';
    }

    public function findOneIdOrNull(MikrotikRouter $router, string $path, string $field, string $value): ?string
    {
        $this->router = $router;
        $key = "{$path}|{$field}|{$value}";
        $this->lookups[] = $key;

        return $this->ids[$key] ?? null;
    }
}
