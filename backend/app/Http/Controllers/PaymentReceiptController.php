<?php

namespace App\Http\Controllers;

use App\Http\Requests\SendPaymentReceiptEmailRequest;
use App\Models\PaymentReceipt;
use App\Services\PaymentReceiptEmailService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class PaymentReceiptController extends Controller
{
    public function __construct(
        private readonly PaymentReceiptEmailService $receiptEmails,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $data = $request->validate([
            'search' => ['nullable', 'string', 'max:100'],
            'year' => ['nullable', 'integer', 'min:2020', 'max:2100'],
            'month' => ['nullable', 'integer', 'min:1', 'max:12'],
            'page' => ['nullable', 'integer', 'min:1'],
            'per_page' => ['nullable', 'integer', 'min:5', 'max:100'],
        ]);

        $query = PaymentReceipt::query()
            ->with(['billingCharge', 'internetService.client.zone:id,name', 'internetService.plan:id,name,monthly_price', 'latestEmailDelivery'])
            ->when($data['search'] ?? null, function (Builder $query, string $search): void {
                $term = '%'.mb_strtolower($search).'%';
                $query->where(function (Builder $query) use ($term): void {
                    $query
                        ->whereRaw('LOWER(receipt_number) LIKE ?', [$term])
                        ->orWhereRaw('LOWER(client_name) LIKE ?', [$term])
                        ->orWhereRaw('LOWER(COALESCE(client_document, \'\')) LIKE ?', [$term])
                        ->orWhereRaw('LOWER(concept) LIKE ?', [$term]);
                });
            })
            ->when(($data['year'] ?? null) && ($data['month'] ?? null), function (Builder $query) use ($data): void {
                $year = (int) $data['year'];
                $month = (int) $data['month'];

                $query->where(function (Builder $query) use ($year, $month): void {
                    $query
                        ->whereHas('billingCharge', fn (Builder $charge) => $charge
                            ->where('period_year', $year)
                            ->where('period_month', $month))
                        ->orWhere(function (Builder $legacy) use ($year, $month): void {
                            $legacy
                                ->whereNull('billing_charge_id')
                                ->whereYear('payment_date', $year)
                                ->whereMonth('payment_date', $month);
                        });
                });
            })
            ->when(($data['year'] ?? null) && ! ($data['month'] ?? null), function (Builder $query) use ($data): void {
                $year = (int) $data['year'];

                $query->where(function (Builder $query) use ($year): void {
                    $query
                        ->whereHas('billingCharge', fn (Builder $charge) => $charge->where('period_year', $year))
                        ->orWhere(fn (Builder $legacy) => $legacy->whereNull('billing_charge_id')->whereYear('payment_date', $year));
                });
            })
            ->when(($data['month'] ?? null) && ! ($data['year'] ?? null), function (Builder $query) use ($data): void {
                $month = (int) $data['month'];

                $query->where(function (Builder $query) use ($month): void {
                    $query
                        ->whereHas('billingCharge', fn (Builder $charge) => $charge->where('period_month', $month))
                        ->orWhere(fn (Builder $legacy) => $legacy->whereNull('billing_charge_id')->whereMonth('payment_date', $month));
                });
            })
            ->latest('payment_date')
            ->latest('id');

        $receipts = $query->paginate($data['per_page'] ?? 10)->withQueryString();

        return response()->json([
            'data' => $receipts->items(),
            'summary' => [
                'count' => (clone $query)->count(),
                'total_received' => round((float) (clone $query)->sum('total_received'), 2),
            ],
            'meta' => [
                'current_page' => $receipts->currentPage(),
                'last_page' => $receipts->lastPage(),
                'per_page' => $receipts->perPage(),
                'total' => $receipts->total(),
            ],
        ]);
    }

    public function show(PaymentReceipt $receipt): JsonResponse
    {
        return response()->json([
            'data' => $receipt->load(['billingCharge', 'internetService.client.zone:id,name', 'internetService.plan:id,name,monthly_price', 'latestEmailDelivery', 'emailDeliveries.sender:id,name']),
        ]);
    }

    public function sendEmail(SendPaymentReceiptEmailRequest $request, PaymentReceipt $receipt): JsonResponse
    {
        $delivery = $this->receiptEmails->send($receipt, $request->validated()['email_to'] ?? null, $request->user()?->id);

        return response()->json([
            'message' => $delivery->status === 'sent'
                ? 'Recibo enviado por correo correctamente.'
                : 'No se pudo enviar el recibo por correo.',
            'data' => $receipt->fresh()->load(['billingCharge', 'internetService.client.zone:id,name', 'internetService.plan:id,name,monthly_price', 'latestEmailDelivery']),
            'email_delivery' => $delivery,
        ]);
    }
}
