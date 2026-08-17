<?php

namespace App\Http\Controllers;

use App\Http\Requests\StorePaymentReceiptRequest;
use App\Services\PaymentReceiptEmailService;
use App\Services\PaymentReceiptService;
use Illuminate\Http\JsonResponse;

class PaymentController extends Controller
{
    public function __construct(
        private readonly PaymentReceiptService $payments,
        private readonly PaymentReceiptEmailService $receiptEmails,
    ) {}

    public function store(StorePaymentReceiptRequest $request): JsonResponse
    {
        $data = $request->validated();
        $receipt = $this->payments->create($data, $request->user()?->id);
        $emailDelivery = null;

        if ($data['send_email'] ?? false) {
            $emailDelivery = $this->receiptEmails->send($receipt, $data['email_to'] ?? null, $request->user()?->id);
        }

        return response()->json([
            'message' => 'Pago registrado correctamente.',
            'data' => $receipt->fresh()->load(['billingCharge', 'internetService.client.zone:id,name', 'internetService.plan:id,name,monthly_price', 'latestEmailDelivery']),
            'email_delivery' => $emailDelivery,
        ], 201);
    }
}
