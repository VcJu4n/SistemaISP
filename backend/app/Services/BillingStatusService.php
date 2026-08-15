<?php

namespace App\Services;

use App\Models\InternetService;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Collection;

class BillingStatusService
{
    /**
     * @param  Collection<int, InternetService>  $services
     * @return array<int, array<string, mixed>>
     */
    public function summarize(Collection $services, int $year, int $month): array
    {
        $periodStart = CarbonImmutable::create($year, $month, 1)->startOfDay();
        $periodEnd = $periodStart->endOfMonth();
        $today = CarbonImmutable::today();
        $asOf = match (true) {
            $today->lessThan($periodStart) => $today,
            $today->betweenIncluded($periodStart, $periodEnd) => $today,
            default => $periodEnd,
        };

        return $services
            ->map(fn (InternetService $service): array => $this->statusForService($service, $periodStart, $periodEnd, $asOf))
            ->values()
            ->all();
    }

    /**
     * @return array<string, mixed>
     */
    public function statusForService(InternetService $service, CarbonImmutable $periodStart, CarbonImmutable $periodEnd, CarbonImmutable $asOf): array
    {
        $receipt = $service->paymentReceipts
            ->first(fn ($receipt) => $receipt->payment_date->betweenIncluded($periodStart, $periodEnd));

        $dueDate = $this->dateInMonth($periodStart, (int) ($service->billing_day ?: 1));
        $cutoffDate = $this->dateInMonth($periodStart, (int) ($service->cutoff_day ?: $service->billing_day ?: 1));
        $graceDeadline = $dueDate->addDays((int) ($service->grace_days ?? 3));
        $amountDue = $service->monthlyBillingAmount();

        if ($receipt) {
            $status = 'paid';
            $label = 'Pagado';
            $paidAmount = (float) $receipt->total_received;
        } elseif ($asOf->greaterThan($graceDeadline)) {
            $status = 'overdue';
            $label = 'Vencido';
            $paidAmount = 0.0;
        } elseif ($asOf->greaterThanOrEqualTo($dueDate)) {
            $status = 'grace';
            $label = 'Perdonazo';
            $paidAmount = 0.0;
        } else {
            $status = 'pending';
            $label = 'Pendiente';
            $paidAmount = 0.0;
        }

        return [
            'service_id' => $service->id,
            'client_id' => $service->client_id,
            'client_name' => $service->client->full_name,
            'client_document' => $service->client->document,
            'client_phone' => $service->client->phone,
            'plan_name' => $service->plan?->name,
            'status' => $status,
            'label' => $label,
            'due_date' => $dueDate->toDateString(),
            'cutoff_date' => $cutoffDate->toDateString(),
            'grace_deadline' => $graceDeadline->toDateString(),
            'amount_due' => round($amountDue, 2),
            'paid_amount' => round($paidAmount, 2),
            'receipt' => $receipt,
        ];
    }

    private function dateInMonth(CarbonImmutable $periodStart, int $day): CarbonImmutable
    {
        $safeDay = max(1, min($day, $periodStart->daysInMonth));

        return $periodStart->setDay($safeDay);
    }
}
