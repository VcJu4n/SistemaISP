<?php

namespace App\Http\Controllers;

use App\Http\Requests\StorePlanRequest;
use App\Http\Requests\UpdatePlanRequest;
use App\Models\Plan;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;

class PlanController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $data = $request->validate([
            'search' => ['nullable', 'string', 'max:100'],
            'active' => ['nullable', 'boolean'],
            'zone_id' => ['nullable', 'integer', 'exists:zones,id'],
            'page' => ['nullable', 'integer', 'min:1'],
            'all' => ['nullable', 'boolean'],
        ]);

        $query = Plan::query()
            ->with('zones:id,name,active')
            ->when($data['search'] ?? null, fn (Builder $query, string $search) => $query->whereRaw('LOWER(name) LIKE ?', ['%'.mb_strtolower($search).'%']))
            ->when(array_key_exists('active', $data), fn (Builder $query) => $query->where('active', $data['active']))
            ->when($data['zone_id'] ?? null, fn (Builder $query, int $zoneId) => $query->whereHas('zones', fn (Builder $query) => $query->where('zones.id', $zoneId)))
            ->latest('id');

        if ($data['all'] ?? false) {
            return response()->json(['data' => $query->limit(1000)->get()]);
        }

        $plans = $query->paginate(10)
            ->withQueryString();

        return response()->json([
            'data' => $plans->items(),
            'meta' => ['current_page' => $plans->currentPage(), 'last_page' => $plans->lastPage(), 'total' => $plans->total()],
        ]);
    }

    public function store(StorePlanRequest $request): JsonResponse
    {
        $data = $request->validated();
        $plan = DB::transaction(function () use ($data): Plan {
            $plan = Plan::query()->create([...Arr::except($data, 'zone_ids'), 'active' => true]);
            $plan->zones()->sync($data['zone_ids']);
            return $plan;
        });

        return response()->json(['message' => 'Plan registrado correctamente.', 'data' => $plan->load('zones:id,name,active')], 201);
    }

    public function show(Plan $plan): JsonResponse
    {
        return response()->json(['data' => $plan->load('zones:id,name,active')]);
    }

    public function update(UpdatePlanRequest $request, Plan $plan): JsonResponse
    {
        $data = $request->validated();
        DB::transaction(function () use ($plan, $data): void {
            $plan->update(Arr::except($data, 'zone_ids'));
            $plan->zones()->sync($data['zone_ids']);
        });

        return response()->json(['message' => 'Plan actualizado correctamente.', 'data' => $plan->fresh()->load('zones:id,name,active')]);
    }
}
