<?php

namespace App\Http\Controllers;

use App\Http\Requests\UpdateServiceBillingRequest;
use App\Models\InternetService;
use App\Services\BillingStatusService;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class BillingController extends Controller
{
    public function __construct(private readonly BillingStatusService $billingStatuses) {}

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
        $data = $request->validate([
            'year' => ['nullable', 'integer', 'min:2020', 'max:2100'],
            'month' => ['nullable', 'integer', 'min:1', 'max:12'],
        ]);

        $today = CarbonImmutable::today();
        $year = (int) ($data['year'] ?? $today->year);
        $month = (int) ($data['month'] ?? $today->month);
        $services = InternetService::query()
            ->with([
                'client.zone:id,name',
                'plan:id,name,monthly_price',
                'paymentReceipts' => fn ($query) => $query
                    ->whereYear('payment_date', $year)
                    ->whereMonth('payment_date', $month)
                    ->latest('payment_date')
                    ->latest('id'),
            ])
            ->where('billing_enabled', true)
            ->get();

        $rows = $this->billingStatuses->summarize($services, $year, $month);
        $totals = [
            'paid' => 0,
            'grace' => 0,
            'overdue' => 0,
            'pending' => 0,
            'paid_total' => 0.0,
            'grace_total' => 0.0,
            'overdue_total' => 0.0,
            'pending_total' => 0.0,
        ];

        foreach ($rows as $row) {
            $totals[$row['status']]++;
            $totals[$row['status'].'_total'] += $row['status'] === 'paid' ? $row['paid_amount'] : $row['amount_due'];
        }

        return response()->json([
            'data' => $rows,
            'summary' => array_map(fn ($value) => is_float($value) ? round($value, 2) : $value, $totals),
        ]);
    }

    public function updateService(UpdateServiceBillingRequest $request, InternetService $service): JsonResponse
    {
        $service->update($request->validated());

        return response()->json([
            'message' => 'Configuracion de cobranza actualizada correctamente.',
            'data' => $service->fresh()->load(['client.zone:id,name', 'plan:id,name,download_mbps,upload_mbps,monthly_price,active']),
        ]);
    }
}
