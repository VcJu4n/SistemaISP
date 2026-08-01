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
        $operation->loadMissing(['service.plan', 'service.mikrotikRouter']);
        $service = $operation->service;

        if (! $service instanceof InternetService) {
            throw new RuntimeException('La operacion no tiene servicio asociado.');
        }

        if (! $service->mikrotikRouter || ! $service->mikrotikRouter->active) {
            throw new RuntimeException('El servicio no tiene un router MikroTik activo asociado.');
        }

        match ($operation->action) {
            MikrotikOperation::ACTION_CREATE_ACCESS => $this->client->executeCommand($service->mikrotikRouter, $this->createAccessCommand($service)),
            MikrotikOperation::ACTION_SUSPEND => $this->suspend($service),
            MikrotikOperation::ACTION_REACTIVATE => $this->client->executeCommand($service->mikrotikRouter, $this->reactivateCommand($service)),
            MikrotikOperation::ACTION_CHANGE_PLAN => $this->client->executeCommand($service->mikrotikRouter, $this->changePlanCommand($service)),
            default => throw new RuntimeException("La accion MikroTik [{$operation->action}] no esta soportada."),
        };
    }

    private function suspend(InternetService $service): void
    {
        $this->client->executeCommand($service->mikrotikRouter, $this->suspendCommand($service));

        if ($service->mikrotik_control_method !== 'pppoe' || ! $service->pppoe_username) {
            return;
        }

        $activeId = $this->client->findOneIdOrNull(
            $service->mikrotikRouter,
            '/ppp/active',
            'name',
            $service->pppoe_username
        );

        if ($activeId !== null) {
            $this->client->executeCommand($service->mikrotikRouter, [
                '/ppp/active/remove',
                '=.id='.$activeId,
            ]);
        }
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
    private function suspendCommand(InternetService $service): array
    {
        return match ($service->mikrotik_control_method) {
            'pppoe' => $this->pppoeSetDisabledCommand($service, true),
            'simple_queue' => $this->simpleQueueSetDisabledCommand($service, true),
            default => throw new RuntimeException("Metodo de control MikroTik [{$service->mikrotik_control_method}] no soportado para suspender acceso."),
        };
    }

    /**
     * @return list<string>
     */
    private function reactivateCommand(InternetService $service): array
    {
        return match ($service->mikrotik_control_method) {
            'pppoe' => $this->pppoeSetDisabledCommand($service, false),
            'simple_queue' => $this->simpleQueueSetDisabledCommand($service, false),
            default => throw new RuntimeException("Metodo de control MikroTik [{$service->mikrotik_control_method}] no soportado para reactivar acceso."),
        };
    }

    /**
     * @return list<string>
     */
    private function changePlanCommand(InternetService $service): array
    {
        return match ($service->mikrotik_control_method) {
            'pppoe' => $this->pppoeChangeProfileCommand($service),
            'simple_queue' => $this->simpleQueueChangeSpeedCommand($service),
            default => throw new RuntimeException("Metodo de control MikroTik [{$service->mikrotik_control_method}] no soportado para cambiar plan."),
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

    /**
     * @return list<string>
     */
    private function pppoeSetDisabledCommand(InternetService $service, bool $disabled): array
    {
        if (! $service->pppoe_username) {
            throw new RuntimeException('El servicio PPPoE no tiene usuario configurado.');
        }

        return [
            '/ppp/secret/set',
            '=.id='.$this->pppoeSecretId($service),
            '=disabled='.($disabled ? 'yes' : 'no'),
            '=comment='.$this->comment($service),
        ];
    }

    /**
     * @return list<string>
     */
    private function pppoeChangeProfileCommand(InternetService $service): array
    {
        if (! $service->pppoe_username || ! $service->pppoe_profile) {
            throw new RuntimeException('La configuracion PPPoE esta incompleta para cambiar perfil.');
        }

        return [
            '/ppp/secret/set',
            '=.id='.$this->pppoeSecretId($service),
            '=profile='.$service->pppoe_profile,
            '=comment='.$this->comment($service),
        ];
    }

    /**
     * @return list<string>
     */
    private function simpleQueueSetDisabledCommand(InternetService $service, bool $disabled): array
    {
        if (config('mikrotik.operations.simple_queue_suspend_strategy') !== 'disable_queue') {
            throw new RuntimeException('La estrategia de suspension Simple Queue configurada no esta soportada.');
        }

        if (! $service->simple_queue_name) {
            throw new RuntimeException('El servicio Simple Queue no tiene nombre de cola configurado.');
        }

        return [
            '/queue/simple/set',
            '=.id='.$this->simpleQueueId($service),
            '=disabled='.($disabled ? 'yes' : 'no'),
            '=comment='.$this->comment($service),
        ];
    }

    /**
     * @return list<string>
     */
    private function simpleQueueChangeSpeedCommand(InternetService $service): array
    {
        if (! $service->simple_queue_name || ! $service->plan) {
            throw new RuntimeException('La configuracion Simple Queue esta incompleta para cambiar velocidad.');
        }

        return [
            '/queue/simple/set',
            '=.id='.$this->simpleQueueId($service),
            '=max-limit='.$this->maxLimit($service),
            '=comment='.$this->comment($service),
        ];
    }

    private function pppoeSecretId(InternetService $service): string
    {
        return $this->client->findOneId($service->mikrotikRouter, '/ppp/secret', 'name', $service->pppoe_username);
    }

    private function simpleQueueId(InternetService $service): string
    {
        return $this->client->findOneId($service->mikrotikRouter, '/queue/simple', 'name', $service->simple_queue_name);
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
