<?php

namespace App\Services;

use App\Models\Client;
use App\Models\InternetService;
use App\Models\MikrotikImportCandidate;
use App\Models\MikrotikRouter;
use App\Models\Plan;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class MikrotikImportService
{
    /**
     * @param  list<array<string, mixed>>  $records
     */
    public function syncCandidates(MikrotikRouter $router, array $records): int
    {
        $count = 0;

        DB::transaction(function () use ($router, $records, &$count): void {
            foreach ($records as $record) {
                if (! in_array($record['source_type'] ?? null, MikrotikImportCandidate::SOURCES, true) || empty($record['identifier'])) {
                    continue;
                }

                $candidate = MikrotikImportCandidate::query()->updateOrCreate([
                    'mikrotik_router_id' => $router->id,
                    'source_type' => $record['source_type'],
                    'identifier' => $record['identifier'],
                ], [
                    'external_id' => $record['external_id'] ?? null,
                    'display_name' => $record['display_name'] ?? null,
                    'ip_address' => $record['ip_address'] ?? null,
                    'mac_address' => $record['mac_address'] ?? null,
                    'profile' => $record['profile'] ?? null,
                    'rate_limit' => $record['rate_limit'] ?? null,
                    'raw_payload' => $record['raw_payload'] ?? $record,
                    'last_seen_at' => now(),
                ]);

                if ($candidate->status !== MikrotikImportCandidate::STATUS_IGNORED) {
                    $this->refreshCandidateRelation($candidate);
                }

                $count++;
            }
        });

        return $count;
    }

    public function linkExistingClient(MikrotikImportCandidate $candidate, Client $client, ?Plan $plan = null): MikrotikImportCandidate
    {
        return DB::transaction(function () use ($candidate, $client, $plan): MikrotikImportCandidate {
            $service = $this->ensureService($candidate, $client, $plan);
            $candidate->update([
                'client_id' => $client->id,
                'internet_service_id' => $service?->id,
                'status' => MikrotikImportCandidate::STATUS_LINKED,
            ]);

            return $candidate->fresh(['client.zone', 'internetService.plan']);
        });
    }

    /**
     * @param  array<string, mixed>  $clientData
     */
    public function createClientFromCandidate(MikrotikImportCandidate $candidate, array $clientData, ?Plan $plan = null): MikrotikImportCandidate
    {
        return DB::transaction(function () use ($candidate, $clientData, $plan): MikrotikImportCandidate {
            $client = Client::query()->create([...$clientData, 'status' => 'active']);

            return $this->linkExistingClient($candidate, $client, $plan);
        });
    }

    public function ignore(MikrotikImportCandidate $candidate): MikrotikImportCandidate
    {
        $candidate->update([
            'client_id' => null,
            'internet_service_id' => null,
            'status' => MikrotikImportCandidate::STATUS_IGNORED,
        ]);

        return $candidate->fresh(['client.zone', 'internetService.plan']);
    }

    private function refreshCandidateRelation(MikrotikImportCandidate $candidate): void
    {
        $service = $this->matchingService($candidate);

        if ($service) {
            $candidate->update([
                'client_id' => $service->client_id,
                'internet_service_id' => $service->id,
                'status' => MikrotikImportCandidate::STATUS_LINKED,
            ]);
            return;
        }

        if ($candidate->status !== MikrotikImportCandidate::STATUS_LINKED) {
            $candidate->update(['status' => MikrotikImportCandidate::STATUS_UNLINKED]);
        }
    }

    private function matchingService(MikrotikImportCandidate $candidate): ?InternetService
    {
        return match ($candidate->source_type) {
            MikrotikImportCandidate::SOURCE_PPPOE => InternetService::query()
                ->where('mikrotik_router_id', $candidate->mikrotik_router_id)
                ->where('mikrotik_control_method', 'pppoe')
                ->where('pppoe_username', $candidate->identifier)
                ->first(),
            MikrotikImportCandidate::SOURCE_SIMPLE_QUEUE => InternetService::query()
                ->where('mikrotik_router_id', $candidate->mikrotik_router_id)
                ->where('mikrotik_control_method', 'simple_queue')
                ->where('simple_queue_name', $candidate->identifier)
                ->first(),
            default => null,
        };
    }

    private function ensureService(MikrotikImportCandidate $candidate, Client $client, ?Plan $plan): ?InternetService
    {
        if (! $candidate->isServiceImportable()) {
            return null;
        }

        $existing = $client->internetService()->first();

        if ($existing) {
            $this->assertCandidateMatchesService($candidate, $existing);
            return $existing;
        }

        if (! $plan) {
            throw ValidationException::withMessages(['plan_id' => ['Selecciona un plan para crear el servicio importado.']]);
        }

        if (! $plan->active || ! $plan->zones()->whereKey($client->zone_id)->exists()) {
            throw ValidationException::withMessages(['plan_id' => ['El plan no esta activo o no esta disponible en la zona del cliente.']]);
        }

        $service = InternetService::query()->create([
            'client_id' => $client->id,
            'plan_id' => $plan->id,
            'status' => 'active',
            'mikrotik_router_id' => $candidate->mikrotik_router_id,
            ...$this->technicalConfig($candidate),
        ]);

        $service->histories()->create([
            'event_type' => 'imported_from_mikrotik',
            'description' => 'Servicio importado desde MikroTik existente.',
            'metadata' => [
                'candidate_id' => $candidate->id,
                'source_type' => $candidate->source_type,
                'identifier' => $candidate->identifier,
            ],
            'occurred_at' => now(),
        ]);

        return $service;
    }

    private function assertCandidateMatchesService(MikrotikImportCandidate $candidate, InternetService $service): void
    {
        $matches = match ($candidate->source_type) {
            MikrotikImportCandidate::SOURCE_PPPOE => $service->mikrotik_control_method === 'pppoe' && $service->pppoe_username === $candidate->identifier,
            MikrotikImportCandidate::SOURCE_SIMPLE_QUEUE => $service->mikrotik_control_method === 'simple_queue' && $service->simple_queue_name === $candidate->identifier,
            default => true,
        };

        if (! $matches) {
            throw ValidationException::withMessages(['client_id' => ['El cliente ya tiene un servicio que no coincide con el registro MikroTik seleccionado.']]);
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function technicalConfig(MikrotikImportCandidate $candidate): array
    {
        return match ($candidate->source_type) {
            MikrotikImportCandidate::SOURCE_PPPOE => [
                'mikrotik_control_method' => 'pppoe',
                'pppoe_username' => $candidate->identifier,
                'pppoe_profile' => $candidate->profile,
                'technical_notes' => 'Importado desde PPPoE MikroTik.',
            ],
            MikrotikImportCandidate::SOURCE_SIMPLE_QUEUE => [
                'mikrotik_control_method' => 'simple_queue',
                'simple_queue_name' => $candidate->identifier,
                'service_ip_address' => $candidate->ip_address,
                'service_mac_address' => $candidate->mac_address,
                'technical_notes' => 'Importado desde Simple Queue MikroTik.',
            ],
            default => [
                'mikrotik_control_method' => 'manual',
                'technical_notes' => 'Importado desde MikroTik sin metodo administrable aun.',
            ],
        };
    }
}
