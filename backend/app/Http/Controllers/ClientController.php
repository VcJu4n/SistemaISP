<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreClientRequest;
use App\Http\Requests\UpdateClientRequest;
use App\Models\Client;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

class ClientController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'search' => ['nullable', 'string', 'max:100'],
            'status' => ['nullable', 'in:active,suspended'],
            'zone_id' => ['nullable', 'integer', 'exists:zones,id'],
            'page' => ['nullable', 'integer', 'min:1'],
            'per_page' => ['nullable', 'integer', 'min:5', 'max:100'],
            'all' => ['nullable', 'boolean'],
            'without_service' => ['nullable', 'boolean'],
            'mikrotik_router_id' => ['nullable', 'integer', 'exists:mikrotik_routers,id'],
            'without_mikrotik' => ['nullable', 'boolean'],
        ]);

        $clients = Client::query()
            ->with('zone:id,name,active')
            ->with('internetService:id,client_id,plan_id,mikrotik_router_id,status,next_due_date,suspended_at,suspension_reason')
            ->with('internetService.plan:id,name,monthly_price')
            ->with('internetService.mikrotikRouter:id,name')
            ->withExists('internetService')
            ->when($validated['search'] ?? null, function (Builder $query, string $search): void {
                $term = '%'.mb_strtolower($search).'%';
                $query->where(function (Builder $query) use ($term): void {
                    $query
                        ->whereRaw('LOWER(full_name) LIKE ?', [$term])
                        ->orWhereRaw('LOWER(document) LIKE ?', [$term])
                        ->orWhereRaw('LOWER(phone) LIKE ?', [$term])
                        ->orWhereRaw('LOWER(COALESCE(email, \'\')) LIKE ?', [$term]);
                });
            })
            ->when($validated['status'] ?? null, fn (Builder $query, string $status) => $query->where('status', $status))
            ->when($validated['zone_id'] ?? null, fn (Builder $query, int $zoneId) => $query->where('zone_id', $zoneId))
            ->when($validated['without_service'] ?? false, fn (Builder $query) => $query->whereDoesntHave('internetService'))
            ->when($validated['mikrotik_router_id'] ?? null, fn (Builder $query, int $routerId) => $query->whereHas(
                'internetService',
                fn (Builder $service) => $service->where('mikrotik_router_id', $routerId)
            ))
            ->when($validated['without_mikrotik'] ?? false, fn (Builder $query) => $query->where(function (Builder $query): void {
                $query->whereDoesntHave('internetService')
                    ->orWhereHas('internetService', fn (Builder $service) => $service->whereNull('mikrotik_router_id'));
            }))
            ->latest('id');

        if ($validated['all'] ?? false) {
            return response()->json(['data' => $clients->limit(1000)->get()]);
        }

        $clients = $clients->paginate($validated['per_page'] ?? 10)
            ->withQueryString();

        return response()->json([
            'data' => $clients->items(),
            'meta' => [
                'current_page' => $clients->currentPage(),
                'last_page' => $clients->lastPage(),
                'per_page' => $clients->perPage(),
                'total' => $clients->total(),
            ],
        ]);
    }

    public function store(StoreClientRequest $request): JsonResponse
    {
        $client = Client::query()->create([
            ...$request->validated(),
            'status' => 'active',
        ]);

        return response()->json([
            'message' => 'Cliente registrado correctamente.',
            'data' => $client->load('zone:id,name,active'),
        ], 201);
    }

    public function show(Client $client): JsonResponse
    {
        return response()->json([
            'data' => $client->load([
                'zone:id,name,active',
                'internetService.plan:id,name,monthly_price,download_mbps,upload_mbps,active',
                'internetService.mikrotikRouter:id,name',
                'internetService.histories.user:id,name',
            ]),
        ]);
    }

    public function update(UpdateClientRequest $request, Client $client): JsonResponse
    {
        $client->update($request->validated());

        return response()->json([
            'message' => 'Cliente actualizado correctamente.',
            'data' => $client->fresh()->load('zone:id,name,active'),
        ]);
    }

    public function destroy(Client $client): JsonResponse
    {
        if ($client->internetService()->exists()) {
            throw ValidationException::withMessages([
                'client' => ['No se puede archivar un cliente que tiene un servicio asignado.'],
            ]);
        }

        $client->delete();

        return response()->json(['message' => 'Cliente archivado correctamente.']);
    }
}
