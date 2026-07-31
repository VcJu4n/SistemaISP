<?php

namespace App\Services\Mikrotik;

use App\Contracts\MikrotikOperationExecutor;
use App\Models\MikrotikOperation;
use RuntimeException;

class DeferredMikrotikOperationExecutor implements MikrotikOperationExecutor
{
    public function execute(MikrotikOperation $operation): void
    {
        throw new RuntimeException('RouterOS executor is not configured yet.');
    }
}
