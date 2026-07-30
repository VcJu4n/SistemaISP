<?php

namespace App\Console\Commands;

use App\Services\Mikrotik\MikrotikOperationProcessor;
use Illuminate\Console\Command;

class ProcessMikrotikOperations extends Command
{
    protected $signature = 'mikrotik:process-pending
        {--limit= : Maximum number of operations to process}
        {--retry-failed : Include failed operations that have attempts remaining}';

    protected $description = 'Process pending MikroTik operations from the database queue.';

    public function handle(MikrotikOperationProcessor $processor): int
    {
        $limit = $this->option('limit') !== null ? (int) $this->option('limit') : null;
        $summary = $processor->process($limit, (bool) $this->option('retry-failed'));

        $this->components->info(sprintf(
            'Processed: %d, synced: %d, failed: %d, skipped: %d.',
            $summary['processed'],
            $summary['synced'],
            $summary['failed'],
            $summary['skipped'],
        ));

        return self::SUCCESS;
    }
}
