<?php

namespace App\Contracts;

use App\Models\MikrotikOperation;

interface MikrotikOperationExecutor
{
    public function execute(MikrotikOperation $operation): void;
}
