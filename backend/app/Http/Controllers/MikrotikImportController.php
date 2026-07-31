<?php

namespace App\Http\Controllers;

use App\Contracts\MikrotikRouterInspector;
use App\Http\Requests\CreateClientFromMikrotikCandidateRequest;
use App\Http\Requests\LinkMikrotikCandidateRequest;
use App\Models\Client;
use App\Models\MikrotikImportCandidate;
use App\Models\MikrotikRouter;
use App\Models\Plan;
use App\Services\MikrotikImportService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Throwable;

class MikrotikImportController extends Controller
{
    public function __construct(
        private readonly MikrotikRouterInspector $inspector,
        private readonly MikrotikImportService $imports,
    ) {}

    public function detect(MikrotikRouter $mikrotikRouter): JsonResponse
    {
        try {
            $result = $this->inspector->detectControlMethod($mikrotikRouter);
            $mikrotikRouter->update([
                'connection_status' => MikrotikRouter::STATUS_CONNECTED,
                'last_successful_connection_at' => now(),
                'last_error' => null,
            ]);

            return response()->json(['message' => 'Metodo de control detectado correctamente.', 'data' => $result]);
        } catch (Throwable $exception) {
            $mikrotikRouter->update([
                'connection_status' => MikrotikRouter::STATUS_DISCONNECTED,
                'last_error' => mb_substr($exception->getMessage(), 0, 2000),
            ]);

            return response()->json(['message' => 'No se pudo detectar el metodo de control.', 'error' => $mikrotikRouter->last_error], 422);
        }
    }

    public function sync(MikrotikRouter $mikrotikRouter): JsonResponse
    {
        try {
            $count = $this->imports->syncCandidates($mikrotikRouter, $this->inspector->importableRecords($mikrotikRouter));
            $mikrotikRouter->update([
                'connection_status' => MikrotikRouter::STATUS_CONNECTED,
                'last_successful_connection_at' => now(),
                'last_error' => null,
            ]);

            return response()->json(['message' => 'Registros MikroTik leidos correctamente.', 'data' => ['synced' => $count]]);
        } catch (Throwable $exception) {
            $mikrotikRouter->update([
                'connection_status' => MikrotikRouter::STATUS_DISCONNECTED,
                'last_error' => mb_substr($exception->getMessage(), 0, 2000),
            ]);

            return response()->json(['message' => 'No se pudieron leer los registros MikroTik.', 'error' => $mikrotikRouter->last_error], 422);
        }
    }

    public function index(Request $request, MikrotikRouter $mikrotikRouter): JsonResponse
    {
        $data = $request->validate([
            'status' => ['nullable', 'in:unlinked,linked,ignored'],
            'source_type' => ['nullable', 'in:pppoe,simple_queue,dhcp_mac,hotspot'],
            'search' => ['nullable', 'string', 'max:100'],
            'all' => ['nullable', 'boolean'],
        ]);

        $query = $mikrotikRouter->importCandidates()
            ->with(['client.zone:id,name', 'internetService.plan:id,name'])
            ->when($data['status'] ?? null, fn (Builder $query, string $status) => $query->where('status', $status))
            ->when($data['source_type'] ?? null, fn (Builder $query, string $source) => $query->where('source_type', $source))
            ->when($data['search'] ?? null, function (Builder $query, string $search): void {
                $term = '%'.mb_strtolower($search).'%';
                $query->where(function (Builder $query) use ($term): void {
                    $query->whereRaw('LOWER(identifier) LIKE ?', [$term])
                        ->orWhereRaw('LOWER(COALESCE(display_name, \'\')) LIKE ?', [$term])
                        ->orWhereRaw('LOWER(COALESCE(CAST(ip_address AS TEXT), \'\')) LIKE ?', [$term])
                        ->orWhereRaw('LOWER(COALESCE(mac_address, \'\')) LIKE ?', [$term]);
                });
            })
            ->latest('last_seen_at')
            ->latest('id');

        if ($data['all'] ?? false) {
            return response()->json(['data' => $query->limit(1000)->get()]);
        }

        $candidates = $query->paginate(15)->withQueryString();

        return response()->json([
            'data' => $candidates->items(),
            'meta' => ['current_page' => $candidates->currentPage(), 'last_page' => $candidates->lastPage(), 'total' => $candidates->total()],
        ]);
    }

    public function link(LinkMikrotikCandidateRequest $request, MikrotikImportCandidate $candidate): JsonResponse
    {
        $data = $request->validated();
        $candidate = $this->imports->linkExistingClient(
            $candidate,
            Client::query()->findOrFail($data['client_id']),
            isset($data['plan_id']) ? Plan::query()->findOrFail($data['plan_id']) : null
        );

        return response()->json(['message' => 'Registro MikroTik vinculado correctamente.', 'data' => $candidate]);
    }

    public function createClient(CreateClientFromMikrotikCandidateRequest $request, MikrotikImportCandidate $candidate): JsonResponse
    {
        $data = $request->validated();
        $plan = isset($data['plan_id']) ? Plan::query()->findOrFail($data['plan_id']) : null;
        unset($data['plan_id']);

        $candidate = $this->imports->createClientFromCandidate($candidate, $data, $plan);

        return response()->json(['message' => 'Cliente importado desde MikroTik correctamente.', 'data' => $candidate], 201);
    }

    public function ignore(MikrotikImportCandidate $candidate): JsonResponse
    {
        return response()->json(['message' => 'Registro MikroTik ignorado correctamente.', 'data' => $this->imports->ignore($candidate)]);
    }
}
