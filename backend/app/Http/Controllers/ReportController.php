<?php

namespace App\Http\Controllers;

use App\Models\InternetService;
use App\Models\Payment;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ReportController extends Controller
{
    public function billingDashboard(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'mikrotik_router_id' => ['required', 'integer', 'exists:mikrotik_routers,id'],
        ]);
        $routerId = $validated['mikrotik_router_id'];
        $today = CarbonImmutable::today();
        $monthStart = $today->startOfMonth()->toDateString();
        $monthEnd = $today->endOfMonth()->toDateString();

        return response()->json([
            'data' => [
                'active_services' => InternetService::query()->where('mikrotik_router_id', $routerId)->where('status', 'active')->count(),
                'overdue_services' => InternetService::query()->where('mikrotik_router_id', $routerId)->where('status', 'active')->whereDate('next_due_date', '<', $today->toDateString())->count(),
                'due_today_services' => InternetService::query()->where('mikrotik_router_id', $routerId)->where('status', 'active')->whereDate('next_due_date', $today->toDateString())->count(),
                'due_next_5_days_services' => InternetService::query()->where('mikrotik_router_id', $routerId)->where('status', 'active')->whereBetween('next_due_date', [$today->toDateString(), $today->addDays(5)->toDateString()])->count(),
                'payments_this_month' => Payment::query()->whereHas('service', fn ($query) => $query->where('mikrotik_router_id', $routerId))->whereBetween('paid_at', [$monthStart, $monthEnd])->count(),
                'collected_this_month' => number_format((float) Payment::query()->whereHas('service', fn ($query) => $query->where('mikrotik_router_id', $routerId))->whereBetween('paid_at', [$monthStart, $monthEnd])->sum('amount'), 2, '.', ''),
            ],
        ]);
    }
}
