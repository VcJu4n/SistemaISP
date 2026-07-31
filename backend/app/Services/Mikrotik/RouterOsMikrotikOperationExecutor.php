<?php

namespace App\Services\Mikrotik;

use App\Contracts\MikrotikOperationExecutor;
use App\Models\InternetService;
use App\Models\MikrotikOperation;
use RuntimeException;

class RouterOsMikrotikOperationExecutor implements MikrotikOperationExecutor
{
    public function __construct(private readonly RouterOsApiClient $client) {}

    public function execute(MikrotikOperation $operation): void
    {
        if ($operation->action !== MikrotikOperation::ACTION_CREATE_ACCESS) {
            throw new RuntimeException("La accion MikroTik [{$operation->action}] aun no esta implementada.");
        }

        $operation->loadMissing(['service.plan', 'service.mikrotikRouter']);
        $service = $operation->service;

        if (! $service instanceof InternetService) {
            throw new RuntimeException('La operacion no tiene servicio asociado.');
        }

        if (! $service->mikrotikRouter || ! $service->mikrotikRouter->active) {
            throw new RuntimeException('El servicio no tiene un router MikroTik activo asociado.');
        }

        $this->client->executeCommand($service->mikrotikRouter, $this->createAccessCommand($service));
    }

    /**
     * @return list<string>
     */
    private function createAccessCommand(InternetService $service): array
    {
        return match ($service->mikrotik_control_method) {
            'pppoe' => $this->pppoeCreateCommand($service),
            'simple_queue' => $this->simpleQueueCreateCommand($service),
            default => throw new RuntimeException("Metodo de control MikroTik [{$service->mikrotik_control_method}] no soportado para crear acceso."),
        };
    }

    /**
     * @return list<string>
     */
    private function pppoeCreateCommand(InternetService $service): array
    {
        if (! $service->pppoe_username || ! $service->pppoe_password || ! $service->pppoe_profile) {
            throw new RuntimeException('La configuracion PPPoE esta incompleta.');
        }

        return [
            '/ppp/secret/add',
            '=name='.$service->pppoe_username,
            '=password='.$service->pppoe_password,
            '=profile='.$service->pppoe_profile,
            '=disabled=no',
            '=comment='.$this->comment($service),
        ];
    }

    /**
     * @return list<string>
     */
    private function simpleQueueCreateCommand(InternetService $service): array
    {
        if (! $service->simple_queue_name || ! $service->service_ip_address || ! $service->plan) {
            throw new RuntimeException('La configuracion Simple Queue esta incompleta.');
        }

        return [
            '/queue/simple/add',
            '=name='.$service->simple_queue_name,
            '=target='.$this->queueTarget($service->service_ip_address),
            '=max-limit='.$this->maxLimit($service),
            '=disabled=no',
            '=comment='.$this->comment($service),
        ];
    }

    private function queueTarget(string $ipAddress): string
    {
        return str_contains($ipAddress, '/') ? $ipAddress : "{$ipAddress}/32";
    }

    private function maxLimit(InternetService $service): string
    {
        return "{$service->plan->upload_mbps}M/{$service->plan->download_mbps}M";
    }

    private function comment(InternetService $service): string
    {
        return "SistemaISP servicio #{$service->id}";
    }
}
