<?php

namespace Tests\Feature;

use App\Contracts\MikrotikOperationExecutor;
use App\Models\MikrotikOperation;
use App\Services\Mikrotik\MikrotikOperationProcessor;
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

    public function test_command_processes_pending_operations(): void
    {
        MikrotikOperation::factory()->count(2)->create();

        $this->app->bind(MikrotikOperationExecutor::class, SuccessfulExecutor::class);

        $this->artisan('mikrotik:process-pending --limit=1')
            ->assertSuccessful();

        $this->assertSame(1, MikrotikOperation::query()->where('status', MikrotikOperation::STATUS_SYNCED)->count());
        $this->assertSame(1, MikrotikOperation::query()->where('status', MikrotikOperation::STATUS_PENDING)->count());
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
