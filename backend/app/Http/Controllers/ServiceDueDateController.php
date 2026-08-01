<?php

namespace App\Http\Controllers;

use App\Http\Requests\UpdateServiceDueDateRequest;
use App\Models\InternetService;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;

class ServiceDueDateController extends Controller
{
    public function update(UpdateServiceDueDateRequest $request, InternetService $service): JsonResponse
    {
        $data = $request->validated();
        $previousDueDate = $service->next_due_date?->toDateString();
        $userId = $request->user()?->id;

        DB::transaction(function () use ($service, $data, $previousDueDate, $userId): void {
            $service->update(['next_due_date' => $data['next_due_date']]);
            $service->histories()->create([
                'user_id' => $userId,
                'event_type' => 'due_date_updated',
                'description' => 'Fecha de vencimiento actualizada manualmente.',
                'metadata' => [
                    'previous_due_date' => $previousDueDate,
                    'next_due_date' => $data['next_due_date'],
                    'reason' => $data['reason'],
                ],
                'occurred_at' => now(),
            ]);
        });

        return response()->json([
            'message' => 'Fecha de vencimiento actualizada correctamente.',
            'data' => $service->fresh()->load(['client.zone:id,name', 'plan:id,name,monthly_price']),
        ]);
    }
}
