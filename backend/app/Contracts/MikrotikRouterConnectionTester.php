<?php

namespace App\Contracts;

use App\Models\MikrotikRouter;
use App\ValueObjects\MikrotikConnectionResult;

interface MikrotikRouterConnectionTester
{
    public function test(MikrotikRouter $router): MikrotikConnectionResult;
}
