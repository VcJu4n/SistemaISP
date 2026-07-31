<?php

namespace App\Http\Controllers;

use App\Contracts\MikrotikRouterConnectionTester;
use App\Http\Requests\StoreMikrotikRouterRequest;
use App\Http\Requests\UpdateMikrotikRouterRequest;
use App\Models\MikrotikRouter;
use App\Services\Mikrotik\RouterOsApiClient;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Illuminate\Validation\Rule;

class MikrotikRouterController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $data = $request->validate([
            'search' => ['nullable', 'string', 'max:100'],
            'active' => ['nullable', 'boolean'],
            'connection_status' => ['nullable', Rule::in(MikrotikRouter::CONNECTION_STATUSES)],
            'all' => ['nullable', 'boolean'],
            'page' => ['nullable', 'integer', 'min:1'],
        ]);

        $query = MikrotikRouter::query()
            ->withCount(['services', 'operations as pending_operations_count' => fn (Builder $query) => $query->whereIn('status', ['pending', 'failed'])])
            ->when($data['search'] ?? null, fn (Builder $query, string $search) => $query->where(function (Builder $query) use ($search) {
                $query->whereRaw('LOWER(name) LIKE ?', ['%'.mb_strtolower($search).'%'])
                    ->orWhereRaw('CAST(ip_address AS TEXT) LIKE ?', ['%'.$search.'%']);
            }))
            ->when(array_key_exists('active', $data), fn (Builder $query) => $query->where('active', $data['active']))
            ->when($data['connection_status'] ?? null, fn (Builder $query, string $status) => $query->where('connection_status', $status))
            ->latest('id');

        if ($data['all'] ?? false) {
            return response()->json(['data' => $query->limit(1000)->get()]);
        }

        $routers = $query->paginate(10)->withQueryString();

        return response()->json([
            'data' => $routers->items(),
            'meta' => ['current_page' => $routers->currentPage(), 'last_page' => $routers->lastPage(), 'total' => $routers->total()],
        ]);
    }

    public function store(StoreMikrotikRouterRequest $request): JsonResponse
    {
        $data = $request->validated();
        $router = MikrotikRouter::query()->create([
            ...$data,
            'api_port' => $data['api_port'] ?? 8728,
            'use_ssl' => $data['use_ssl'] ?? false,
            'active' => $data['active'] ?? true,
            'connection_status' => MikrotikRouter::STATUS_PENDING,
        ]);

        return response()->json(['message' => 'MikroTik registrado correctamente.', 'data' => $router], 201);
    }

    public function show(MikrotikRouter $mikrotikRouter): JsonResponse
    {
        return response()->json([
            'data' => $mikrotikRouter->loadCount([
                'services',
                'operations as pending_operations_count' => fn (Builder $query) => $query->whereIn('status', ['pending', 'failed']),
            ]),
        ]);
    }

    public function update(UpdateMikrotikRouterRequest $request, MikrotikRouter $mikrotikRouter): JsonResponse
    {
        $data = $request->validated();

        if (! array_key_exists('password', $data) || $data['password'] === null || $data['password'] === '') {
            $data = Arr::except($data, 'password');
        }

        if ($this->connectionSettingsChanged($mikrotikRouter, $data)) {
            $data['connection_status'] = MikrotikRouter::STATUS_PENDING;
            $data['last_error'] = null;
            $data['last_successful_connection_at'] = null;
        }

        $mikrotikRouter->update($data);

        return response()->json(['message' => 'MikroTik actualizado correctamente.', 'data' => $mikrotikRouter->fresh()]);
    }

    public function testConnection(MikrotikRouter $mikrotikRouter, MikrotikRouterConnectionTester $tester): JsonResponse
    {
        $result = $tester->test($mikrotikRouter);

        $mikrotikRouter->update([
            'connection_status' => $result->connected ? MikrotikRouter::STATUS_CONNECTED : MikrotikRouter::STATUS_DISCONNECTED,
            'last_successful_connection_at' => $result->connected ? now() : $mikrotikRouter->last_successful_connection_at,
            'last_error' => $result->connected ? null : $result->error,
        ]);

        return response()->json([
            'message' => $result->connected ? 'Conexion con MikroTik correcta.' : 'No se pudo conectar con MikroTik.',
            'data' => $mikrotikRouter->fresh(),
        ], $result->connected ? 200 : 422);
    }

    public function pppoeProfiles(MikrotikRouter $mikrotikRouter, RouterOsApiClient $client): JsonResponse
    {
        $profiles = collect($client->read($mikrotikRouter, '/ppp/profile', ['name']))
            ->pluck('name')
            ->filter(fn ($name) => is_string($name) && $name !== '')
            ->unique()
            ->sort()
            ->values();

        return response()->json(['data' => $profiles]);
    }

    private function connectionSettingsChanged(MikrotikRouter $router, array $data): bool
    {
        foreach (['ip_address', 'api_port', 'username', 'password', 'use_ssl'] as $field) {
            if (array_key_exists($field, $data) && $router->{$field} !== $data[$field]) {
                return true;
            }
        }

        return false;
    }
}
