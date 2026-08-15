<?php

namespace App\Services\Mikrotik;

use App\Contracts\MikrotikRouterConnectionTester;
use App\Models\MikrotikRouter;
use App\ValueObjects\MikrotikConnectionResult;
use Throwable;

class MikrotikRouterHealthMonitor
{
    public function __construct(private readonly MikrotikRouterConnectionTester $tester) {}

    public function check(MikrotikRouter $router): MikrotikConnectionResult
    {
        try {
            $result = $this->tester->test($router);
        } catch (Throwable $exception) {
            $result = MikrotikConnectionResult::disconnected($exception->getMessage());
        }
        $checkedAt = now();

        $router->update([
            'connection_status' => $result->connected
                ? MikrotikRouter::STATUS_CONNECTED
                : MikrotikRouter::STATUS_DISCONNECTED,
            'last_checked_at' => $checkedAt,
            'last_successful_connection_at' => $result->connected
                ? $checkedAt
                : $router->last_successful_connection_at,
            'last_error' => $result->connected ? null : $result->error,
        ]);

        return $result;
    }

    /**
     * @return array{checked: int, connected: int, disconnected: int}
     */
    public function checkAllActive(): array
    {
        $summary = ['checked' => 0, 'connected' => 0, 'disconnected' => 0];

        MikrotikRouter::query()
            ->where('active', true)
            ->orderBy('id')
            ->eachById(function (MikrotikRouter $router) use (&$summary): void {
                $result = $this->check($router);
                $summary['checked']++;
                $summary[$result->connected ? 'connected' : 'disconnected']++;
            });

        return $summary;
    }
}
