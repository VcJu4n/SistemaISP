<?php

namespace App\Http\Controllers;

use App\Http\Requests\StorePaymentReceiptRequest;
use App\Models\PaymentReceipt;
use App\Services\PaymentReceiptService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class PaymentReceiptController extends Controller
{
    public function __construct(private readonly PaymentReceiptService $receipts) {}

    public function index(Request $request): JsonResponse
    {
        $data = $request->validate([
            'search' => ['nullable', 'string', 'max:100'],
            'year' => ['nullable', 'integer', 'min:2020', 'max:2100'],
            'month' => ['nullable', 'integer', 'min:1', 'max:12'],
            'page' => ['nullable', 'integer', 'min:1'],
            'per_page' => ['nullable', 'integer', 'min:5', 'max:100'],
        ]);

        $receipts = PaymentReceipt::query()
            ->with(['internetService.client.zone:id,name', 'internetService.plan:id,name,monthly_price'])
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
            ->when($data['year'] ?? null, fn (Builder $query, int $year) => $query->whereYear('payment_date', $year))
            ->when($data['month'] ?? null, fn (Builder $query, int $month) => $query->whereMonth('payment_date', $month))
            ->latest('payment_date')
            ->latest('id')
            ->paginate($data['per_page'] ?? 10)
            ->withQueryString();

        return response()->json([
            'data' => $receipts->items(),
            'meta' => [
                'current_page' => $receipts->currentPage(),
                'last_page' => $receipts->lastPage(),
                'per_page' => $receipts->perPage(),
                'total' => $receipts->total(),
            ],
        ]);
    }

    public function store(StorePaymentReceiptRequest $request): JsonResponse
    {
        $receipt = $this->receipts->create($request->validated(), $request->user()?->id);

        return response()->json([
            'message' => 'Recibo registrado correctamente.',
            'data' => $receipt,
        ], 201);
    }

    public function show(PaymentReceipt $receipt): JsonResponse
    {
        return response()->json([
            'data' => $receipt->load(['internetService.client.zone:id,name', 'internetService.plan:id,name,monthly_price']),
        ]);
    }
}
