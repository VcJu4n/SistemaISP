<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreZoneRequest;
use App\Http\Requests\UpdateZoneRequest;
use App\Models\Zone;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ZoneController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $data = $request->validate([
            'search' => ['nullable', 'string', 'max:100'],
            'active' => ['nullable', 'boolean'],
            'all' => ['nullable', 'boolean'],
            'page' => ['nullable', 'integer', 'min:1'],
        ]);

        $query = Zone::query()
            ->withCount('clients')
            ->when($data['search'] ?? null, fn (Builder $query, string $search) => $query->whereRaw('LOWER(name) LIKE ?', ['%'.mb_strtolower($search).'%']))
            ->when(array_key_exists('active', $data), fn (Builder $query) => $query->where('active', $data['active']))
            ->orderBy('name');

        if ($data['all'] ?? false) {
            return response()->json(['data' => $query->get()]);
        }

        $zones = $query->paginate(10)->withQueryString();

        return response()->json([
            'data' => $zones->items(),
            'meta' => ['current_page' => $zones->currentPage(), 'last_page' => $zones->lastPage(), 'total' => $zones->total()],
        ]);
    }

    public function store(StoreZoneRequest $request): JsonResponse
    {
        $zone = Zone::query()->create([...$request->validated(), 'active' => true]);
        return response()->json(['message' => 'Zona registrada correctamente.', 'data' => $zone], 201);
    }

    public function show(Zone $zone): JsonResponse
    {
        return response()->json(['data' => $zone->loadCount('clients')]);
    }

    public function update(UpdateZoneRequest $request, Zone $zone): JsonResponse
    {
        $zone->update($request->validated());
        return response()->json(['message' => 'Zona actualizada correctamente.', 'data' => $zone->fresh()->loadCount('clients')]);
    }
}
