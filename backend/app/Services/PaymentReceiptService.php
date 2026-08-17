<?php

namespace App\Services;

use App\Models\BillingCharge;
use App\Models\PaymentReceipt;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class PaymentReceiptService
{
    public function __construct(
        private readonly ReceiptCalculator $calculator,
        private readonly MoneyToWords $moneyToWords,
        private readonly BillingChargeService $billingCharges,
    ) {}

    /**
     * @param  array<string, mixed>  $input
     */
    public function create(array $input, ?int $userId): PaymentReceipt
    {
        return DB::transaction(function () use ($input, $userId): PaymentReceipt {
            $charge = $this->resolveCharge($input);
            $service = $charge->internetService()->with(['client', 'plan'])->firstOrFail();

            if (! $service->billing_enabled) {
                throw ValidationException::withMessages([
                    'billing_charge_id' => ['La cobranza de este servicio esta desactivada.'],
                ]);
            }

            $subtotal = $this->resolveSubtotal($input, $charge);
            $totals = $this->calculator->calculate(
                $subtotal,
                $input['iva_rate'] ?? 0,
                $input['retention_rate'] ?? 0,
            );

            $currency = trim((string) ($charge?->currency ?? $input['currency'] ?? 'Bolivianos')) ?: 'Bolivianos';
            $cutoffDate = $charge->cutoff_date?->toDateString();
            $billingPeriod = $charge->period_label;
            $concept = $charge->concept;
            $latestId = (int) PaymentReceipt::query()->lockForUpdate()->max('id');

            $receipt = PaymentReceipt::query()->create([
                'receipt_number' => str_pad((string) ($latestId + 1), 3, '0', STR_PAD_LEFT),
                'billing_charge_id' => $charge->id,
                'internet_service_id' => $service->id,
                'client_id' => $service->client_id,
                'created_by' => $userId,
                'client_name' => $service->client->full_name,
                'client_document' => $service->client->document,
                'client_phone' => $service->client->phone,
                'client_email' => $service->client->email,
                'plan_name' => $service->plan?->name,
                'payment_date' => $input['payment_date'],
                'cutoff_date' => $cutoffDate,
                'billing_period' => $billingPeriod,
                'concept' => $concept,
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

            $this->billingCharges->registerPayment($charge, $totals['subtotal']);

            return $receipt->load(['internetService.client.zone:id,name', 'internetService.plan:id,name,monthly_price']);
        });
    }

    /**
     * @param  array<string, mixed>  $input
     */
    private function resolveCharge(array $input): BillingCharge
    {
        $charge = BillingCharge::query()
            ->with(['client', 'internetService.plan'])
            ->lockForUpdate()
            ->findOrFail($input['billing_charge_id']);

        if ($charge->status === BillingCharge::STATUS_CANCELLED) {
            throw ValidationException::withMessages([
                'billing_charge_id' => ['Este cobro esta anulado.'],
            ]);
        }

        if ((float) $charge->balance <= 0 || $charge->status === BillingCharge::STATUS_PAID) {
            throw ValidationException::withMessages([
                'billing_charge_id' => ['Este cobro ya fue pagado.'],
            ]);
        }

        return $charge;
    }

    /**
     * @param  array<string, mixed>  $input
     */
    private function resolveSubtotal(array $input, BillingCharge $charge): float
    {
        $amount = round((float) ($input['payment_amount'] ?? $charge->balance), 2);
        $balance = round((float) $charge->balance, 2);

        if ($amount <= 0) {
            throw ValidationException::withMessages([
                'payment_amount' => ['El monto a pagar debe ser mayor a cero.'],
            ]);
        }

        if ($amount > $balance) {
            throw ValidationException::withMessages([
                'payment_amount' => ['El monto a pagar no puede superar el saldo del cobro.'],
            ]);
        }

        return $amount;
    }
}
