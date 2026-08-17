<?php

namespace App\Http\Controllers;

use App\Http\Requests\UpdateServiceBillingRequest;
use App\Models\InternetService;
use App\Services\BillingChargeService;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class BillingController extends Controller
{
    public function __construct(private readonly BillingChargeService $billingCharges) {}

    public function services(): JsonResponse
    {
        $services = InternetService::query()
            ->with(['client.zone:id,name', 'plan:id,name,download_mbps,upload_mbps,monthly_price,active'])
            ->latest('id')
            ->limit(1000)
            ->get();

        return response()->json(['data' => $services]);
    }

    public function status(Request $request): JsonResponse
    {
        return $this->charges($request);
    }

    public function charges(Request $request): JsonResponse
    {
        [$data, $year, $month] = $this->periodInput($request, [
            'status' => ['nullable', 'string', 'in:paid,partial,grace,overdue,pending,cancelled'],
            'search' => ['nullable', 'string', 'max:100'],
            'page' => ['nullable', 'integer', 'min:1'],
            'per_page' => ['nullable', 'integer', 'min:5', 'max:1000'],
        ]);

        $charges = $this->billingCharges->listForPeriod($year, $month, $data);
        $summaryCharges = $this->billingCharges->allForPeriod($year, $month, $data);
        $rows = collect($charges->items())
            ->map(fn ($charge) => $this->billingCharges->toResponseRow($charge))
            ->values()
            ->all();

        return response()->json([
            'data' => $rows,
            'summary' => $this->billingCharges->summary($summaryCharges),
            'meta' => [
                'current_page' => $charges->currentPage(),
                'last_page' => $charges->lastPage(),
                'per_page' => $charges->perPage(),
                'total' => $charges->total(),
            ],
        ]);
    }

    public function generateCharges(Request $request): JsonResponse
    {
        [, $year, $month] = $this->periodInput($request);
        $result = $this->billingCharges->generateForPeriod($year, $month, $request->user()?->id);
        $charges = $this->billingCharges->listForPeriod($year, $month, ['per_page' => 1000]);
        $summaryCharges = $this->billingCharges->allForPeriod($year, $month);
        $rows = collect($charges->items())
            ->map(fn ($charge) => $this->billingCharges->toResponseRow($charge))
            ->values()
            ->all();

        return response()->json([
            'message' => "Cobros generados: {$result['created']}. Existentes: {$result['existing']}. Omitidos: {$result['skipped']}.",
            'generation' => $result,
            'data' => $rows,
            'summary' => $this->billingCharges->summary($summaryCharges),
            'meta' => [
                'current_page' => $charges->currentPage(),
                'last_page' => $charges->lastPage(),
                'per_page' => $charges->perPage(),
                'total' => $charges->total(),
            ],
        ], 201);
    }

    public function updateService(UpdateServiceBillingRequest $request, InternetService $service): JsonResponse
    {
        $service->update($request->validated());

        return response()->json([
            'message' => 'Configuracion de cobranza actualizada correctamente.',
            'data' => $service->fresh()->load(['client.zone:id,name', 'plan:id,name,download_mbps,upload_mbps,monthly_price,active']),
        ]);
    }

    /**
     * @param  array<string, array<int, string>>  $extraRules
     * @return array{0: array<string, mixed>, 1: int, 2: int}
     */
    private function periodInput(Request $request, array $extraRules = []): array
    {
        $data = $request->validate([
            'year' => ['nullable', 'integer', 'min:2020', 'max:2100'],
            'month' => ['nullable', 'integer', 'min:1', 'max:12'],
            ...$extraRules,
        ]);
        $today = CarbonImmutable::today();
        $year = (int) ($data['year'] ?? $today->year);
        $month = (int) ($data['month'] ?? $today->month);

        return [$data, $year, $month];
    }
}
