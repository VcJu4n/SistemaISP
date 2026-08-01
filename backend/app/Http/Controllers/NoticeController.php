<?php

namespace App\Http\Controllers;

use App\Models\InternetService;
use App\Models\NotificationLog;
use App\Models\NotificationTemplate;
use App\Services\NotificationMessageService;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class NoticeController extends Controller
{
    public function __construct(private readonly NotificationMessageService $messages) {}

    public function kanban(): JsonResponse
    {
        $today = CarbonImmutable::today();

        return response()->json([
            'data' => [
                'columns' => [
                    $this->column('payment_due_5', 'Vence en 5 dias', $this->servicesForDueDate($today->addDays(5), 5)),
                    $this->column('payment_due_2', 'Vence en 2 dias', $this->servicesForDueDate($today->addDays(2), 2)),
                    $this->column('payment_due_today', 'Vence hoy', $this->servicesForDueDate($today, 0)),
                    $this->column('suspended', 'Suspendidos', $this->suspendedServices()),
                ],
                'generated_at' => now(),
            ],
        ]);
    }

    public function summary(Request $request): JsonResponse
    {
        $data = $request->validate([
            'date_from' => ['nullable', 'date'],
            'date_to' => ['nullable', 'date', 'after_or_equal:date_from'],
        ]);

        $today = CarbonImmutable::today();
        $dateFrom = $data['date_from'] ?? $today->startOfMonth()->toDateString();
        $dateTo = $data['date_to'] ?? $today->endOfMonth()->toDateString();

        $query = NotificationLog::query()
            ->when($dateFrom, fn (Builder $query, string $date) => $query->whereDate('sent_at', '>=', $date))
            ->when($dateTo, fn (Builder $query, string $date) => $query->whereDate('sent_at', '<=', $date));

        $byType = (clone $query)
            ->select('type', DB::raw('COUNT(*) as notifications_count'))
            ->groupBy('type')
            ->orderBy('type')
            ->get();

        return response()->json([
            'data' => [
                'date_from' => $dateFrom,
                'date_to' => $dateTo,
                'notifications_count' => (clone $query)->count(),
                'by_type' => $byType,
            ],
        ]);
    }

    public function sendWhatsapp(Request $request, InternetService $service): JsonResponse
    {
        $data = $request->validate([
            'template_key' => ['required', Rule::in(NotificationTemplate::KEYS)],
        ]);

        $service->load(['client.zone', 'plan']);
        $this->validateTemplateMatchesService($data['template_key'], $service);

        $template = NotificationTemplate::query()
            ->where('key', $data['template_key'])
            ->where('active', true)
            ->firstOrFail();

        $phone = $this->messages->normalizePhone($service->client->phone);
        if ($phone === '') {
            throw ValidationException::withMessages(['phone' => ['El cliente no tiene un numero de telefono valido.']]);
        }

        $daysRemaining = $this->daysRemaining($service);
        $message = $this->messages->render($template, $service, $daysRemaining);
        $url = $this->messages->whatsappUrl($service->client->phone, $message);
        $userId = $request->user()?->id;

        $log = DB::transaction(function () use ($service, $template, $phone, $message, $userId): NotificationLog {
            $log = NotificationLog::query()->create([
                'client_id' => $service->client_id,
                'internet_service_id' => $service->id,
                'notification_template_id' => $template->id,
                'user_id' => $userId,
                'type' => $template->key,
                'channel' => NotificationLog::CHANNEL_WHATSAPP,
                'phone' => $phone,
                'message' => $message,
                'sent_at' => now(),
            ]);

            $service->histories()->create([
                'user_id' => $userId,
                'event_type' => 'notification_sent',
                'description' => 'Aviso de WhatsApp enviado: '.$template->name.'.',
                'metadata' => [
                    'notification_log_id' => $log->id,
                    'template_key' => $template->key,
                    'channel' => NotificationLog::CHANNEL_WHATSAPP,
                    'phone' => $phone,
                    'message' => $message,
                ],
                'occurred_at' => now(),
            ]);

            return $log;
        });

        return response()->json([
            'message' => 'Aviso registrado correctamente.',
            'data' => [
                'log' => $log->fresh()->load(['template:id,key,name', 'user:id,name']),
                'wa_url' => $url,
                'message' => $message,
            ],
        ], 201);
    }

    public function clientLogs(Request $request, \App\Models\Client $client): JsonResponse
    {
        $data = $request->validate([
            'page' => ['nullable', 'integer', 'min:1'],
            'per_page' => ['nullable', 'integer', 'min:5', 'max:100'],
        ]);

        $logs = NotificationLog::query()
            ->with(['template:id,key,name', 'user:id,name'])
            ->where('client_id', $client->id)
            ->orderByDesc('sent_at')
            ->latest('id')
            ->paginate($data['per_page'] ?? 10)
            ->withQueryString();

        return response()->json([
            'data' => $logs->items(),
            'meta' => [
                'current_page' => $logs->currentPage(),
                'last_page' => $logs->lastPage(),
                'per_page' => $logs->perPage(),
                'total' => $logs->total(),
            ],
        ]);
    }

    private function servicesForDueDate(CarbonImmutable $date, int $days): array
    {
        return InternetService::query()
            ->with(['client.zone:id,name', 'plan:id,name,monthly_price'])
            ->where('status', 'active')
            ->whereDate('next_due_date', $date->toDateString())
            ->orderBy('next_due_date')
            ->orderBy('id')
            ->get()
            ->map(fn (InternetService $service) => $this->card($service, $days))
            ->values()
            ->all();
    }

    private function suspendedServices(): array
    {
        return InternetService::query()
            ->with(['client.zone:id,name', 'plan:id,name,monthly_price'])
            ->where('status', 'suspended')
            ->orderByDesc('suspended_at')
            ->orderBy('id')
            ->get()
            ->map(fn (InternetService $service) => $this->card($service, $this->daysRemaining($service)))
            ->values()
            ->all();
    }

    private function column(string $key, string $title, array $cards): array
    {
        return [
            'key' => $key,
            'title' => $title,
            'template_key' => $key,
            'cards' => $cards,
        ];
    }

    private function card(InternetService $service, ?int $daysRemaining): array
    {
        return [
            'service_id' => $service->id,
            'client_id' => $service->client_id,
            'client_name' => $service->client->full_name,
            'phone' => $service->client->phone,
            'zone' => $service->client->zone?->name,
            'plan_name' => $service->plan->name,
            'amount' => number_format((float) ($service->plan->monthly_price ?? 0), 2, '.', ''),
            'next_due_date' => $service->next_due_date?->toDateString(),
            'days_remaining' => $daysRemaining,
            'status' => $service->status,
            'suspension_reason' => $service->suspension_reason,
        ];
    }

    private function daysRemaining(InternetService $service): ?int
    {
        if (! $service->next_due_date) {
            return null;
        }

        return (int) CarbonImmutable::today()->diffInDays(CarbonImmutable::parse($service->next_due_date), false);
    }

    private function validateTemplateMatchesService(string $templateKey, InternetService $service): void
    {
        if ($templateKey === NotificationTemplate::SUSPENDED && $service->status !== 'suspended') {
            throw ValidationException::withMessages(['template_key' => ['La plantilla de suspension solo aplica a servicios suspendidos.']]);
        }

        if ($templateKey !== NotificationTemplate::SUSPENDED && $service->status !== 'active') {
            throw ValidationException::withMessages(['template_key' => ['La plantilla de pago solo aplica a servicios activos.']]);
        }

        if ($templateKey !== NotificationTemplate::SUSPENDED) {
            $allowedDays = [
                NotificationTemplate::PAYMENT_DUE_5 => 5,
                NotificationTemplate::PAYMENT_DUE_2 => 2,
                NotificationTemplate::PAYMENT_DUE_TODAY => 0,
            ];

            if ($this->daysRemaining($service) !== $allowedDays[$templateKey]) {
                throw ValidationException::withMessages(['template_key' => ['El servicio no pertenece a esta columna de avisos.']]);
            }
        }
    }
}
