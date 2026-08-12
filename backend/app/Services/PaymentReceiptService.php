<?php

namespace App\Services;

use App\Models\InternetService;
use App\Models\PaymentReceipt;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class PaymentReceiptService
{
    public function __construct(
        private readonly ReceiptCalculator $calculator,
        private readonly MoneyToWords $moneyToWords,
    ) {}

    /**
     * @param  array<string, mixed>  $input
     */
    public function create(array $input, ?int $userId): PaymentReceipt
    {
        return DB::transaction(function () use ($input, $userId): PaymentReceipt {
            $service = InternetService::query()
                ->with(['client', 'plan'])
                ->lockForUpdate()
                ->findOrFail($input['internet_service_id']);

            if (! $service->billing_enabled) {
                throw ValidationException::withMessages([
                    'internet_service_id' => ['La cobranza de este servicio esta desactivada.'],
                ]);
            }

            $totals = $this->calculator->calculate(
                $input['subtotal'],
                $input['iva_rate'] ?? 0,
                $input['retention_rate'] ?? 0,
            );

            $currency = trim((string) ($input['currency'] ?? 'Bolivianos')) ?: 'Bolivianos';
            $latestId = (int) PaymentReceipt::query()->lockForUpdate()->max('id');

            $receipt = PaymentReceipt::query()->create([
                'receipt_number' => str_pad((string) ($latestId + 1), 3, '0', STR_PAD_LEFT),
                'internet_service_id' => $service->id,
                'client_id' => $service->client_id,
                'created_by' => $userId,
                'client_name' => $service->client->full_name,
                'client_document' => $service->client->document,
                'client_phone' => $service->client->phone,
                'plan_name' => $service->plan?->name,
                'payment_date' => $input['payment_date'],
                'cutoff_date' => $input['cutoff_date'] ?? null,
                'billing_period' => trim((string) ($input['billing_period'] ?? '')) ?: null,
                'concept' => trim((string) $input['concept']),
                'observations' => trim((string) ($input['observations'] ?? '')) ?: null,
                'currency' => $currency,
                'subtotal' => $totals['subtotal'],
                'iva_rate' => $input['iva_rate'] ?? 0,
                'retention_rate' => $input['retention_rate'] ?? 0,
                'iva' => $totals['iva'],
                'retention' => $totals['retention'],
                'total_received' => $totals['total_received'],
                'amount_words' => $this->moneyToWords->convert($totals['total_received'], $currency),
            ]);

            $service->histories()->create([
                'event_type' => 'payment_received',
                'description' => "Recibo {$receipt->receipt_number} registrado por Bs {$receipt->total_received}.",
                'metadata' => [
                    'receipt_id' => $receipt->id,
                    'receipt_number' => $receipt->receipt_number,
                    'payment_date' => $receipt->payment_date->toDateString(),
                    'total_received' => $receipt->total_received,
                ],
                'occurred_at' => now(),
            ]);

            return $receipt->load(['internetService.client.zone:id,name', 'internetService.plan:id,name,monthly_price']);
        });
    }
}
