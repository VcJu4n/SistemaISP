<?php

namespace App\Services\Mikrotik;

use App\Contracts\MikrotikOperationExecutor;
use App\Models\MikrotikOperation;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Throwable;

class MikrotikOperationProcessor
{
    public function __construct(private readonly MikrotikOperationExecutor $executor) {}

    public function process(?int $limit = null, bool $retryFailed = false): array
    {
        $operations = $this->runnableOperations($limit, $retryFailed);
        $summary = ['processed' => 0, 'synced' => 0, 'failed' => 0, 'skipped' => 0];

        foreach ($operations as $operation) {
            $claimedOperation = $this->claim($operation, $retryFailed);

            if (! $claimedOperation) {
                $summary['skipped']++;
                continue;
            }

            $summary['processed']++;

            try {
                $this->executor->execute($claimedOperation);
                $claimedOperation->markSynced();
                $summary['synced']++;
            } catch (Throwable $exception) {
                $claimedOperation->markFailed($this->errorMessage($exception));
                $summary['failed']++;
            }
        }

        return $summary;
    }

    private function runnableOperations(?int $limit, bool $retryFailed): Collection
    {
        $maxAttempts = (int) config('mikrotik.operations.max_attempts', 3);
        $batchSize = $limit ?? (int) config('mikrotik.operations.batch_size', 20);
        $staleProcessingBefore = now()->subMinutes((int) config('mikrotik.operations.stale_processing_minutes', 10));

        return MikrotikOperation::query()
            ->with(['service.client.zone', 'service.plan', 'service.mikrotikRouter'])
            ->where('attempts', '<', $maxAttempts)
            ->where(function ($query) use ($retryFailed, $staleProcessingBefore): void {
                $query->where('status', MikrotikOperation::STATUS_PENDING);

                if ($retryFailed) {
                    $query->orWhere('status', MikrotikOperation::STATUS_FAILED);
                }

                $query->orWhere(function ($query) use ($staleProcessingBefore): void {
                    $query->where('status', MikrotikOperation::STATUS_PROCESSING)
                        ->where(function ($query) use ($staleProcessingBefore): void {
                            $query->whereNull('last_attempt_at')
                                ->orWhere('last_attempt_at', '<=', $staleProcessingBefore);
                        });
                });
            })
            ->oldest('id')
            ->limit($batchSize)
            ->get();
    }

    private function claim(MikrotikOperation $operation, bool $retryFailed): ?MikrotikOperation
    {
        return DB::transaction(function () use ($operation, $retryFailed): ?MikrotikOperation {
            $lockedOperation = MikrotikOperation::query()
                ->whereKey($operation->id)
                ->lockForUpdate()
                ->first();

            if (! $lockedOperation || ! $this->canProcess($lockedOperation, $retryFailed)) {
                return null;
            }

            $lockedOperation->markProcessing();

            return $lockedOperation->fresh(['service.client.zone', 'service.plan', 'service.mikrotikRouter']);
        });
    }

    private function canProcess(MikrotikOperation $operation, bool $retryFailed): bool
    {
        if ($operation->status === MikrotikOperation::STATUS_PENDING) {
            return true;
        }

        if ($retryFailed && $operation->status === MikrotikOperation::STATUS_FAILED) {
            return true;
        }

        return $operation->status === MikrotikOperation::STATUS_PROCESSING
            && $this->isStaleProcessing($operation);
    }

    private function isStaleProcessing(MikrotikOperation $operation): bool
    {
        if ($operation->last_attempt_at === null) {
            return true;
        }

        return $operation->last_attempt_at->lte(now()->subMinutes((int) config('mikrotik.operations.stale_processing_minutes', 10)));
    }

    private function errorMessage(Throwable $exception): string
    {
        return mb_substr($exception->getMessage(), 0, 2000);
    }
}
