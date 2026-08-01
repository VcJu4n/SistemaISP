<?php

namespace App\Http\Controllers;

use App\Models\NotificationTemplate;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class NotificationTemplateController extends Controller
{
    public function index(): JsonResponse
    {
        return response()->json([
            'data' => NotificationTemplate::query()
                ->orderByRaw("CASE key WHEN 'payment_due_5' THEN 1 WHEN 'payment_due_2' THEN 2 WHEN 'payment_due_today' THEN 3 WHEN 'suspended' THEN 4 ELSE 5 END")
                ->get(),
        ]);
    }

    public function update(Request $request, NotificationTemplate $notificationTemplate): JsonResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:100'],
            'body' => ['required', 'string', 'max:1000'],
            'active' => ['required', 'boolean'],
            'key' => ['nullable', Rule::in(NotificationTemplate::KEYS)],
        ]);

        $notificationTemplate->update([
            'name' => $data['name'],
            'body' => $data['body'],
            'active' => $data['active'],
        ]);

        return response()->json([
            'message' => 'Plantilla actualizada correctamente.',
            'data' => $notificationTemplate->fresh(),
        ]);
    }
}
