<?php

namespace App\Http\Controllers;

use App\Http\Requests\ChangeServicePlanRequest;
use App\Http\Requests\StoreInternetServiceRequest;
use App\Http\Requests\SuspendServiceRequest;
use App\Http\Requests\UpdateServiceTechnicalConfigRequest;
use App\Models\Client;
use App\Models\InternetService;
use App\Models\Plan;
use App\Services\MikrotikOperationRecorder;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class InternetServiceController extends Controller
{
    public function __construct(private readonly MikrotikOperationRecorder $mikrotikOperations) {}

    public function index(Request $request): JsonResponse
    {
        $data = $request->validate([
            'search' => ['nullable', 'string', 'max:100'],
            'status' => ['nullable', 'in:active,suspended'],
            'zone_id' => ['nullable', 'integer', 'exists:zones,id'],
            'plan_id' => ['nullable', 'integer', 'exists:plans,id'],
            'page' => ['nullable', 'integer', 'min:1'],
        ]);

        $services = InternetService::query()
            ->with(['client.zone:id,name', 'plan:id,name,download_mbps,upload_mbps,monthly_price,active'])
            ->when($data['search'] ?? null, function (Builder $query, string $search): void {
                $term = '%'.mb_strtolower($search).'%';
                $query->whereHas('client', fn (Builder $query) => $query
                    ->whereRaw('LOWER(full_name) LIKE ?', [$term])
                    ->orWhereRaw('LOWER(document) LIKE ?', [$term])
                    ->orWhereRaw('LOWER(phone) LIKE ?', [$term]));
            })
            ->when($data['status'] ?? null, fn (Builder $query, string $status) => $query->where('status', $status))
            ->when($data['zone_id'] ?? null, fn (Builder $query, int $zoneId) => $query->whereHas('client', fn (Builder $query) => $query->where('zone_id', $zoneId)))
            ->when($data['plan_id'] ?? null, fn (Builder $query, int $planId) => $query->where('plan_id', $planId))
            ->latest('id')
            ->paginate(10)
            ->withQueryString();

        return response()->json([
            'data' => $services->items(),
            'meta' => ['current_page' => $services->currentPage(), 'last_page' => $services->lastPage(), 'total' => $services->total()],
        ]);
    }

    public function store(StoreInternetServiceRequest $request): JsonResponse
    {
        $data = $request->validated();
        $client = Client::query()->with('zone')->findOrFail($data['client_id']);
        $plan = Plan::query()->with('zones:id')->findOrFail($data['plan_id']);

        if ($client->status !== 'active') {
            throw ValidationException::withMessages(['client_id' => ['El cliente debe estar activo.']]);
        }

        $this->validatePlanForClient($plan, $client);

        $data = StoreInternetServiceRequest::normalizeTechnicalConfig($data);

        $service = DB::transaction(function () use ($data, $client, $plan): InternetService {
            $installationDate = ($data['installation_date'] ?? null)
                ? CarbonImmutable::parse($data['installation_date'])
                : CarbonImmutable::today();
            $service = InternetService::query()->create([
                ...$data,
                'status' => 'active',
                'next_due_date' => $installationDate->addMonthNoOverflow()->toDateString(),
            ]);
            $service->histories()->create([
                'event_type' => 'created',
                'description' => "Servicio creado con el plan {$plan->name}.",
                'metadata' => ['plan_id' => $plan->id, 'plan_name' => $plan->name, 'next_due_date' => $service->next_due_date?->toDateString()],
                'occurred_at' => now(),
            ]);
            $this->mikrotikOperations->createAccess($service);
            return $service;
        });

        return response()->json(['message' => 'Servicio asignado correctamente.', 'data' => $this->loadService($service)], 201);
    }

    public function show(InternetService $service): JsonResponse
    {
        return response()->json(['data' => $this->loadService($service, true)]);
    }

    public function suspend(SuspendServiceRequest $request, InternetService $service): JsonResponse
    {
        if ($service->status !== 'active') {
            throw ValidationException::withMessages(['status' => ['Solo se puede suspender un servicio activo.']]);
        }

        $data = $request->validated();
        $labels = ['debt' => 'Mora', 'client_request' => 'Solicitud del cliente', 'technical' => 'Motivo técnico', 'other' => 'Otro'];

        DB::transaction(function () use ($service, $data, $labels): void {
            $service->update(['status' => 'suspended', 'suspended_at' => now(), 'suspension_reason' => $data['reason'], 'suspension_notes' => $data['notes'] ?? null]);
            $service->histories()->create([
                'event_type' => 'suspended',
                'description' => 'Servicio suspendido: '.$labels[$data['reason']].'.',
                'metadata' => ['reason' => $data['reason'], 'reason_label' => $labels[$data['reason']], 'notes' => $data['notes'] ?? null],
                'occurred_at' => now(),
            ]);
            $this->mikrotikOperations->suspend($service, [
                'reason' => $data['reason'],
                'reason_label' => $labels[$data['reason']],
                'notes' => $data['notes'] ?? null,
            ]);
        });

        return response()->json(['message' => 'Servicio suspendido correctamente.', 'data' => $this->loadService($service)]);
    }

    public function reactivate(InternetService $service): JsonResponse
    {
        if ($service->status !== 'suspended') {
            throw ValidationException::withMessages(['status' => ['Solo se puede reactivar un servicio suspendido.']]);
        }

        DB::transaction(function () use ($service): void {
            $service->update(['status' => 'active', 'suspended_at' => null, 'suspension_reason' => null, 'suspension_notes' => null]);
            $service->histories()->create(['event_type' => 'reactivated', 'description' => 'Servicio reactivado.', 'occurred_at' => now()]);
            $this->mikrotikOperations->reactivate($service);
        });

        return response()->json(['message' => 'Servicio reactivado correctamente.', 'data' => $this->loadService($service)]);
    }

    public function changePlan(ChangeServicePlanRequest $request, InternetService $service): JsonResponse
    {
        $data = $request->validated();
        $plan = Plan::query()->with('zones:id')->findOrFail($data['plan_id']);
        $service->load('client');

        if ($plan->id === $service->plan_id) {
            throw ValidationException::withMessages(['plan_id' => ['Selecciona un plan diferente al actual.']]);
        }

        $this->validatePlanForClient($plan, $service->client);
        $previousPlan = $service->plan;

        DB::transaction(function () use ($service, $plan, $previousPlan, $data): void {
            $changes = ['plan_id' => $plan->id];

            if ($service->mikrotik_control_method === 'pppoe') {
                $changes['pppoe_profile'] = $data['pppoe_profile'];
            }

            $service->update($changes);
            $service->setRelation('plan', $plan);
            $service->histories()->create([
                'event_type' => 'plan_changed',
                'description' => "Plan cambiado de {$previousPlan->name} a {$plan->name}.",
                'metadata' => [
                    'previous_plan_id' => $previousPlan->id,
                    'previous_plan_name' => $previousPlan->name,
                    'new_plan_id' => $plan->id,
                    'new_plan_name' => $plan->name,
                    'pppoe_profile' => $changes['pppoe_profile'] ?? null,
                ],
                'occurred_at' => now(),
            ]);
            $this->mikrotikOperations->changePlan($service, $previousPlan, $plan);
        });

        return response()->json(['message' => 'Plan cambiado correctamente.', 'data' => $this->loadService($service)]);
    }

    public function updateTechnicalConfig(UpdateServiceTechnicalConfigRequest $request, InternetService $service): JsonResponse
    {
        $data = UpdateServiceTechnicalConfigRequest::normalizeTechnicalConfig($request->validated());

        if (($data['mikrotik_control_method'] ?? null) === 'pppoe' && (! array_key_exists('pppoe_password', $data) || $data['pppoe_password'] === null)) {
            unset($data['pppoe_password']);
        }

        $before = $service->only($this->technicalConfigFields());
        $syncFieldsChanged = $this->technicalSyncFieldsChanged($service, $data);

        DB::transaction(function () use ($service, $data, $before, $syncFieldsChanged): void {
            $service->update($data);
            $service->histories()->create([
                'event_type' => 'technical_config_updated',
                'description' => 'Configuracion tecnica MikroTik actualizada.',
                'metadata' => [
                    'before' => $before,
                    'after' => $service->fresh()->only($this->technicalConfigFields()),
                ],
                'occurred_at' => now(),
            ]);

            if ($syncFieldsChanged && $service->fresh()->requiresMikrotikSync()) {
                $this->mikrotikOperations->createAccess($service->fresh());
            }
        });

        return response()->json(['message' => 'Configuracion tecnica actualizada correctamente.', 'data' => $this->loadService($service)]);
    }

    private function validatePlanForClient(Plan $plan, Client $client): void
    {
        if (! $plan->active) {
            throw ValidationException::withMessages(['plan_id' => ['El plan seleccionado está inactivo.']]);
        }
        if (! $plan->zones->contains('id', $client->zone_id)) {
            throw ValidationException::withMessages(['plan_id' => ['El plan no está disponible en la zona del cliente.']]);
        }
    }

    private function loadService(InternetService $service, bool $withHistory = false): InternetService
    {
        $relations = [
            'client.zone:id,name',
            'plan:id,name,download_mbps,upload_mbps,monthly_price,active',
            'mikrotikRouter:id,name,connection_status,active',
        ];
        if ($withHistory) $relations[] = 'histories.user:id,name';
        if ($withHistory) $relations[] = 'mikrotikOperations.router:id,name,connection_status,active';
        return $service->fresh()->load($relations);
    }

    /**
     * @return list<string>
     */
    private function technicalConfigFields(): array
    {
        return [
            'mikrotik_router_id',
            'mikrotik_control_method',
            'pppoe_username',
            'pppoe_profile',
            'simple_queue_name',
            'service_ip_address',
            'service_mac_address',
            'client_antenna_ip',
            'client_antenna_mac',
            'client_antenna_brand_model',
            'client_antenna_device_name',
            'technical_notes',
        ];
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function technicalSyncFieldsChanged(InternetService $service, array $data): bool
    {
        foreach (['mikrotik_router_id', 'mikrotik_control_method', 'pppoe_username', 'pppoe_password', 'pppoe_profile', 'simple_queue_name', 'service_ip_address', 'service_mac_address'] as $field) {
            if (array_key_exists($field, $data) && $service->{$field} !== $data[$field]) {
                return true;
            }
        }

        return false;
    }
}
