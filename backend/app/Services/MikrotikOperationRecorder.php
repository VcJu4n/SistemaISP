<?php

namespace App\Services;

use App\Models\InternetService;
use App\Models\MikrotikOperation;
use App\Models\Plan;
use InvalidArgumentException;

class MikrotikOperationRecorder
{
    public function createAccess(InternetService $service): ?MikrotikOperation
    {
        return $this->queue($service, MikrotikOperation::ACTION_CREATE_ACCESS);
    }

    public function suspend(InternetService $service, array $context = []): ?MikrotikOperation
    {
        return $this->queue($service, MikrotikOperation::ACTION_SUSPEND, $context);
    }

    public function reactivate(InternetService $service): ?MikrotikOperation
    {
        return $this->queue($service, MikrotikOperation::ACTION_REACTIVATE);
    }

    public function changePlan(InternetService $service, Plan $previousPlan, Plan $newPlan): ?MikrotikOperation
    {
        return $this->queue($service, MikrotikOperation::ACTION_CHANGE_PLAN, [
            'previous_plan' => $this->planPayload($previousPlan),
            'new_plan' => $this->planPayload($newPlan),
        ]);
    }

    private function queue(InternetService $service, string $action, array $context = []): ?MikrotikOperation
    {
        if (! in_array($action, MikrotikOperation::ACTIONS, true)) {
            throw new InvalidArgumentException("Unsupported MikroTik action [{$action}].");
        }

        $service->loadMissing(['client.zone', 'plan', 'mikrotikRouter']);

        if (! $service->requiresMikrotikSync()) {
            return null;
        }

        return $service->mikrotikOperations()->create([
            'mikrotik_router_id' => $service->mikrotik_router_id,
            'action' => $action,
            'status' => MikrotikOperation::STATUS_PENDING,
            'attempts' => 0,
            'payload' => [
                'action' => $action,
                'service' => [
                    'id' => $service->id,
                    'status' => $service->status,
                    'control_method' => $service->mikrotik_control_method,
                    'router_id' => $service->mikrotik_router_id,
                ],
                'router' => $service->mikrotikRouter ? [
                    'id' => $service->mikrotikRouter->id,
                    'name' => $service->mikrotikRouter->name,
                    'connection_status' => $service->mikrotikRouter->connection_status,
                ] : null,
                'client' => [
                    'id' => $service->client->id,
                    'full_name' => $service->client->full_name,
                    'document' => $service->client->document,
                    'phone' => $service->client->phone,
                    'zone' => $service->client->zone?->name,
                ],
                'plan' => $this->planPayload($service->plan),
                'technical_config' => $service->technicalConfig(),
                'context' => $context,
            ],
        ]);
    }

    private function planPayload(Plan $plan): array
    {
        return [
            'id' => $plan->id,
            'name' => $plan->name,
            'download_mbps' => $plan->download_mbps,
            'upload_mbps' => $plan->upload_mbps,
            'max_limit' => "{$plan->download_mbps}M/{$plan->upload_mbps}M",
        ];
    }
}
