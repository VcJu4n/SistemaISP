<?php

namespace App\Services\Mikrotik;

use App\Contracts\MikrotikRouterConnectionTester;
use App\Models\MikrotikRouter;
use App\ValueObjects\MikrotikConnectionResult;
use Throwable;

class RouterOsMikrotikRouterConnectionTester implements MikrotikRouterConnectionTester
{
    public function __construct(private readonly RouterOsApiClient $client) {}

    public function test(MikrotikRouter $router): MikrotikConnectionResult
    {
        try {
            $this->client->testConnection($router);

            return MikrotikConnectionResult::connected();
        } catch (Throwable $exception) {
            return MikrotikConnectionResult::disconnected($exception->getMessage());
        }
    }
}
