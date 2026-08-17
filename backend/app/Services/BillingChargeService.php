<?php

namespace App\Services;

use App\Models\BillingCharge;
use App\Models\InternetService;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;

class BillingChargeService
{
    /**
     * @return array{created: int, existing: int, skipped: int}
     */
    public function generateForPeriod(int $year, int $month, ?int $userId): array
    {
        $periodStart = CarbonImmutable::create($year, $month, 1)->startOfDay();
        $result = ['created' => 0, 'existing' => 0, 'skipped' => 0];

        InternetService::query()
            ->with(['client', 'plan'])
            ->where('billing_enabled', true)
            ->orderBy('id')
            ->chunkById(100, function ($services) use ($periodStart, $year, $month, $userId, &$result): void {
                foreach ($services as $service) {
                    $amount = round($service->monthlyBillingAmount(), 2);

                    if ($amount <= 0) {
                        $result['skipped']++;
                        continue;
                    }

                    $charge = BillingCharge::query()->firstOrCreate(
                        [
                            'internet_service_id' => $service->id,
                            'period_year' => $year,
                            'period_month' => $month,
                        ],
                        $this->chargePayload($service, $periodStart, $amount, $userId)
                    );

                    $result[$charge->wasRecentlyCreated ? 'created' : 'existing']++;
                }
            });

        $this->refreshOverdueStatuses($year, $month);

        return $result;
    }

    public function listForPeriod(int $year, int $month, array $filters = []): LengthAwarePaginator
    {
        $this->refreshOverdueStatuses($year, $month);

        return $this->queryForPeriod($year, $month, $filters)
            ->orderByRaw("CASE status WHEN 'overdue' THEN 0 WHEN 'partial' THEN 1 WHEN 'pending' THEN 2 WHEN 'paid' THEN 3 ELSE 4 END")
            ->orderBy('due_date')
            ->orderBy('id')
            ->paginate($filters['per_page'] ?? 100)
            ->withQueryString();
    }

    /**
     * @return Collection<int, BillingCharge>
     */
    public function allForPeriod(int $year, int $month, array $filters = []): Collection
    {
        $this->refreshOverdueStatuses($year, $month);

        return $this->queryForPeriod($year, $month, $filters)->get();
    }

    /**
     * @param  iterable<int, BillingCharge>  $charges
     * @return array<string, int|float>
     */
    public function summary(iterable $charges): array
    {
        $summary = [
            'paid' => 0,
            'partial' => 0,
            'grace' => 0,
            'overdue' => 0,
            'pending' => 0,
            'cancelled' => 0,
            'paid_total' => 0.0,
            'partial_total' => 0.0,
            'grace_total' => 0.0,
            'overdue_total' => 0.0,
            'pending_total' => 0.0,
            'cancelled_total' => 0.0,
            'total_charged' => 0.0,
            'total_paid' => 0.0,
            'total_balance' => 0.0,
        ];

        foreach ($charges as $charge) {
            $status = $charge->displayStatus();
            $summary[$status]++;
            $summary[$status.'_total'] += $status === BillingCharge::STATUS_PAID
                ? (float) $charge->paid_amount
                : (float) $charge->balance;
            $summary['total_charged'] += (float) $charge->original_amount;
            $summary['total_paid'] += (float) $charge->paid_amount;
            $summary['total_balance'] += (float) $charge->balance;
        }

        return array_map(fn ($value) => is_float($value) ? round($value, 2) : $value, $summary);
    }

    public function registerPayment(BillingCharge $charge, float $amount): BillingCharge
    {
        $paidAmount = round((float) $charge->paid_amount + $amount, 2);
        $balance = max(0, round((float) $charge->original_amount - $paidAmount, 2));

        $charge->update([
            'paid_amount' => $paidAmount,
            'balance' => $balance,
            'status' => $balance <= 0 ? BillingCharge::STATUS_PAID : BillingCharge::STATUS_PARTIAL,
        ]);

        return $charge->fresh();
    }

    public function refreshOverdueStatuses(int $year, int $month): void
    {
        BillingCharge::query()
            ->where('period_year', $year)
            ->where('period_month', $month)
            ->whereIn('status', [BillingCharge::STATUS_PENDING, BillingCharge::STATUS_PARTIAL])
            ->where('balance', '>', 0)
            ->whereDate('grace_deadline', '<', now()->toDateString())
            ->update(['status' => BillingCharge::STATUS_OVERDUE]);
    }

    /**
     * @return array<string, mixed>
     */
    public function toResponseRow(BillingCharge $charge): array
    {
        return [
            'id' => $charge->id,
            'internet_service_id' => $charge->internet_service_id,
            'service_id' => $charge->internet_service_id,
            'client_id' => $charge->client_id,
            'client_name' => $charge->client->full_name,
            'client_document' => $charge->client->document,
            'client_phone' => $charge->client->phone,
            'client_email' => $charge->client->email,
            'zone_name' => $charge->client->zone?->name,
            'plan_name' => $charge->internetService->plan?->name,
            'status' => $charge->displayStatus(),
            'charge_status' => $charge->status,
            'label' => $charge->displayLabel(),
            'period_year' => $charge->period_year,
            'period_month' => $charge->period_month,
            'period_label' => $charge->period_label,
            'issue_date' => $charge->issue_date?->toDateString(),
            'due_date' => $charge->due_date?->toDateString(),
            'cutoff_date' => $charge->cutoff_date?->toDateString(),
            'grace_deadline' => $charge->grace_deadline?->toDateString(),
            'concept' => $charge->concept,
            'currency' => $charge->currency,
            'amount_due' => round((float) $charge->original_amount, 2),
            'original_amount' => round((float) $charge->original_amount, 2),
            'paid_amount' => round((float) $charge->paid_amount, 2),
            'balance' => round((float) $charge->balance, 2),
            'receipts' => $charge->receipts,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function chargePayload(InternetService $service, CarbonImmutable $periodStart, float $amount, ?int $userId): array
    {
        $dueDate = $this->dateInMonth($periodStart, (int) ($service->billing_day ?: 1));
        $cutoffDate = $this->dateInMonth($periodStart, (int) ($service->cutoff_day ?: $service->billing_day ?: 1));
        $graceDeadline = $dueDate->addDays((int) ($service->grace_days ?? 3));
        $planName = trim((string) ($service->plan?->name ?? ''));

        return [
            'client_id' => $service->client_id,
            'created_by' => $userId,
            'period_label' => $this->periodLabel($periodStart),
            'issue_date' => $periodStart->toDateString(),
            'due_date' => $dueDate->toDateString(),
            'cutoff_date' => $cutoffDate->toDateString(),
            'grace_deadline' => $graceDeadline->toDateString(),
            'concept' => $planName ? "Servicio de Internet - {$planName}" : 'Servicio de Internet',
            'currency' => 'Bolivianos',
            'original_amount' => $amount,
            'paid_amount' => 0,
            'balance' => $amount,
            'status' => BillingCharge::STATUS_PENDING,
        ];
    }

    private function queryForPeriod(int $year, int $month, array $filters): Builder
    {
        return BillingCharge::query()
            ->with([
                'client.zone:id,name',
                'internetService.plan:id,name,download_mbps,upload_mbps,monthly_price,active',
                'receipts.latestEmailDelivery',
            ])
            ->where('period_year', $year)
            ->where('period_month', $month)
            ->when($filters['status'] ?? null, function (Builder $query, string $status): void {
                if ($status === 'grace') {
                    $query->where('status', BillingCharge::STATUS_PENDING)
                        ->whereDate('due_date', '<=', now()->toDateString())
                        ->whereDate('grace_deadline', '>=', now()->toDateString());

                    return;
                }

                if ($status === BillingCharge::STATUS_PENDING) {
                    $query->where('status', BillingCharge::STATUS_PENDING)
                        ->whereDate('due_date', '>', now()->toDateString());

                    return;
                }

                $query->where('status', $status);
            })
            ->when($filters['search'] ?? null, function (Builder $query, string $search): void {
                $term = '%'.mb_strtolower($search).'%';
                $query->where(function (Builder $query) use ($term): void {
                    $query
                        ->whereRaw('LOWER(period_label) LIKE ?', [$term])
                        ->orWhereRaw('LOWER(concept) LIKE ?', [$term])
                        ->orWhereHas('client', fn (Builder $client) => $client
                            ->whereRaw('LOWER(full_name) LIKE ?', [$term])
                            ->orWhereRaw('LOWER(COALESCE(document, \'\')) LIKE ?', [$term])
                            ->orWhereRaw('LOWER(COALESCE(phone, \'\')) LIKE ?', [$term]))
                        ->orWhereHas('internetService.plan', fn (Builder $plan) => $plan->whereRaw('LOWER(name) LIKE ?', [$term]));
                });
            });
    }

    private function dateInMonth(CarbonImmutable $periodStart, int $day): CarbonImmutable
    {
        $safeDay = max(1, min($day, $periodStart->daysInMonth));

        return $periodStart->setDay($safeDay);
    }

    private function periodLabel(CarbonImmutable $periodStart): string
    {
        $months = [
            1 => 'Enero',
            2 => 'Febrero',
            3 => 'Marzo',
            4 => 'Abril',
            5 => 'Mayo',
            6 => 'Junio',
            7 => 'Julio',
            8 => 'Agosto',
            9 => 'Septiembre',
            10 => 'Octubre',
            11 => 'Noviembre',
            12 => 'Diciembre',
        ];

        return $months[$periodStart->month].' '.$periodStart->year;
    }
}
