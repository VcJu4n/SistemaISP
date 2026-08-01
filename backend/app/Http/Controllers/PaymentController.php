<?php

namespace App\Http\Controllers;

use App\Http\Requests\StorePaymentRequest;
use App\Models\Client;
use App\Models\InternetService;
use App\Models\Payment;
use App\Services\MikrotikOperationRecorder;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class PaymentController extends Controller
{
    public function __construct(private readonly MikrotikOperationRecorder $mikrotikOperations) {}

    public function index(Request $request): JsonResponse
    {
        $data = $request->validate([
            'search' => ['nullable', 'string', 'max:100'],
            'client_id' => ['nullable', 'integer', 'exists:clients,id'],
            'zone_id' => ['nullable', 'integer', 'exists:zones,id'],
            'date_from' => ['nullable', 'date'],
            'date_to' => ['nullable', 'date', 'after_or_equal:date_from'],
            'page' => ['nullable', 'integer', 'min:1'],
            'per_page' => ['nullable', 'integer', 'min:5', 'max:100'],
        ]);

        $payments = $this->paymentQuery($data)
            ->paginate($data['per_page'] ?? 10)
            ->withQueryString();

        return response()->json([
            'data' => $payments->items(),
            'meta' => [
                'current_page' => $payments->currentPage(),
                'last_page' => $payments->lastPage(),
                'per_page' => $payments->perPage(),
                'total' => $payments->total(),
            ],
        ]);
    }

    public function store(StorePaymentRequest $request): JsonResponse
    {
        $data = $request->validated();
        $client = Client::query()
            ->with(['internetService.plan', 'internetService.client'])
            ->findOrFail($data['client_id']);

        $service = $client->internetService;
        if (! $service) {
            throw ValidationException::withMessages(['client_id' => ['El cliente no tiene un servicio asignado.']]);
        }

        $paidAt = CarbonImmutable::parse($data['paid_at'])->startOfDay();
        $billingPeriod = $paidAt->format('Y-m');
        $duplicate = Payment::query()
            ->where('internet_service_id', $service->id)
            ->where('billing_period', $billingPeriod)
            ->latest('paid_at')
            ->first();

        if ($duplicate && ! ($data['confirm_duplicate'] ?? false)) {
            return response()->json([
                'message' => 'Ya existe un pago registrado para este cliente en el mismo mes.',
                'code' => 'duplicate_payment',
                'data' => $duplicate->load(['client:id,full_name', 'user:id,name']),
            ], 409);
        }

        $previousDueDate = $service->next_due_date?->toDateString();
        $nextDueDate = $this->calculateNextDueDate($service, $paidAt);
        $userId = $request->user()?->id;

        $payment = DB::transaction(function () use ($service, $client, $data, $paidAt, $billingPeriod, $previousDueDate, $nextDueDate, $userId, $duplicate): Payment {
            $payment = Payment::query()->create([
                'client_id' => $client->id,
                'internet_service_id' => $service->id,
                'user_id' => $userId,
                'amount' => $data['amount'],
                'paid_at' => $paidAt->toDateString(),
                'billing_period' => $billingPeriod,
                'payment_method' => $data['payment_method'],
                'observation' => $data['observation'] ?? null,
                'previous_due_date' => $previousDueDate,
                'next_due_date' => $nextDueDate->toDateString(),
                'duplicate_confirmed' => (bool) $duplicate,
            ]);

            $service->update(['next_due_date' => $nextDueDate->toDateString()]);
            $service->histories()->create([
                'user_id' => $userId,
                'event_type' => 'payment_registered',
                'description' => 'Pago registrado por Bs '.number_format((float) $data['amount'], 2, '.', '').'.',
                'metadata' => [
                    'payment_id' => $payment->id,
                    'amount' => number_format((float) $data['amount'], 2, '.', ''),
                    'payment_method' => $data['payment_method'],
                    'paid_at' => $paidAt->toDateString(),
                    'billing_period' => $billingPeriod,
                    'previous_due_date' => $previousDueDate,
                    'next_due_date' => $nextDueDate->toDateString(),
                    'duplicate_confirmed' => (bool) $duplicate,
                    'observation' => $data['observation'] ?? null,
                ],
                'occurred_at' => now(),
            ]);

            if (($data['reactivate_if_suspended'] ?? false) && $service->status === 'suspended' && $service->suspension_reason === 'debt') {
                $service->update(['status' => 'active', 'suspended_at' => null, 'suspension_reason' => null, 'suspension_notes' => null]);
                $service->histories()->create([
                    'user_id' => $userId,
                    'event_type' => 'reactivated',
                    'description' => 'Servicio reactivado automaticamente al registrar pago.',
                    'metadata' => ['payment_id' => $payment->id],
                    'occurred_at' => now(),
                ]);
                $this->mikrotikOperations->reactivate($service->fresh());
            }

            return $payment;
        });

        return response()->json([
            'message' => 'Pago registrado correctamente.',
            'data' => $payment->fresh()->load($this->paymentRelations()),
        ], 201);
    }

    public function show(Payment $payment): JsonResponse
    {
        return response()->json(['data' => $payment->load($this->paymentRelations())]);
    }

    public function clientPayments(Request $request, Client $client): JsonResponse
    {
        $data = $request->validate([
            'date_from' => ['nullable', 'date'],
            'date_to' => ['nullable', 'date', 'after_or_equal:date_from'],
            'page' => ['nullable', 'integer', 'min:1'],
            'per_page' => ['nullable', 'integer', 'min:5', 'max:100'],
        ]);

        $payments = $this->paymentQuery([...$data, 'client_id' => $client->id])
            ->paginate($data['per_page'] ?? 10)
            ->withQueryString();

        return response()->json([
            'data' => $payments->items(),
            'meta' => [
                'current_page' => $payments->currentPage(),
                'last_page' => $payments->lastPage(),
                'per_page' => $payments->perPage(),
                'total' => $payments->total(),
            ],
        ]);
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function paymentQuery(array $data): Builder
    {
        return Payment::query()
            ->with($this->paymentRelations())
            ->when($data['search'] ?? null, function (Builder $query, string $search): void {
                $term = '%'.mb_strtolower($search).'%';
                $query->whereHas('client', fn (Builder $query) => $query
                    ->whereRaw('LOWER(full_name) LIKE ?', [$term])
                    ->orWhereRaw('LOWER(document) LIKE ?', [$term])
                    ->orWhereRaw('LOWER(phone) LIKE ?', [$term]));
            })
            ->when($data['client_id'] ?? null, fn (Builder $query, int $clientId) => $query->where('client_id', $clientId))
            ->when($data['zone_id'] ?? null, fn (Builder $query, int $zoneId) => $query->whereHas('client', fn (Builder $query) => $query->where('zone_id', $zoneId)))
            ->when($data['date_from'] ?? null, fn (Builder $query, string $date) => $query->whereDate('paid_at', '>=', $date))
            ->when($data['date_to'] ?? null, fn (Builder $query, string $date) => $query->whereDate('paid_at', '<=', $date))
            ->orderByDesc('paid_at')
            ->latest('id');
    }

    private function calculateNextDueDate(InternetService $service, CarbonImmutable $paidAt): CarbonImmutable
    {
        if ($service->next_due_date) {
            return CarbonImmutable::parse($service->next_due_date)->addMonthNoOverflow()->startOfDay();
        }

        return $paidAt->addMonthNoOverflow()->startOfDay();
    }

    /**
     * @return list<string>
     */
    private function paymentRelations(): array
    {
        return [
            'client.zone:id,name',
            'service.plan:id,name,monthly_price',
            'user:id,name',
        ];
    }
}
