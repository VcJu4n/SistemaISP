<?php

namespace App\Console\Commands;

use App\Models\MikrotikRouter;
use App\Services\Mikrotik\MikrotikRouterHealthMonitor;
use Illuminate\Console\Command;

class CheckMikrotikRouters extends Command
{
    protected $signature = 'mikrotik:check-routers';

    protected $description = 'Check every active MikroTik router independently.';

    public function handle(MikrotikRouterHealthMonitor $monitor): int
    {
        $summary = $monitor->checkAllActive();

        $this->table(
            ['Router', 'Endpoint', 'Status', 'Error'],
            MikrotikRouter::query()
                ->where('active', true)
                ->orderBy('id')
                ->get()
                ->map(fn (MikrotikRouter $router): array => [
                    $router->name,
                    "{$router->ip_address}:{$router->api_port}",
                    $router->connection_status,
                    $router->last_error ?? '-',
                ]),
        );

        $this->components->info(sprintf(
            'Checked: %d, connected: %d, disconnected: %d.',
            $summary['checked'],
            $summary['connected'],
            $summary['disconnected'],
        ));

        return self::SUCCESS;
    }
}
