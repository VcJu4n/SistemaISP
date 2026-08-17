<?php

namespace App\Services;

use App\Mail\PaymentReceiptMail;
use App\Models\PaymentReceipt;
use App\Models\PaymentReceiptEmailDelivery;
use Illuminate\Support\Facades\Mail;
use Throwable;

class PaymentReceiptEmailService
{
    public function send(PaymentReceipt $receipt, ?string $recipientEmail, ?int $userId): PaymentReceiptEmailDelivery
    {
        $email = $this->resolveEmail($receipt, $recipientEmail);

        $delivery = $receipt->emailDeliveries()->create([
            'sent_by' => $userId,
            'recipient_email' => $email ?: 'sin-correo',
            'status' => PaymentReceiptEmailDelivery::STATUS_PENDING,
        ]);

        if (! $email) {
            $delivery->update([
                'status' => PaymentReceiptEmailDelivery::STATUS_FAILED,
                'error_message' => 'El cliente no tiene un correo valido registrado.',
            ]);

            return $delivery->fresh();
        }

        try {
            Mail::to($email)->send(new PaymentReceiptMail($receipt));
            $delivery->update([
                'recipient_email' => $email,
                'status' => PaymentReceiptEmailDelivery::STATUS_SENT,
                'error_message' => null,
                'sent_at' => now(),
            ]);
        } catch (Throwable $throwable) {
            $delivery->update([
                'recipient_email' => $email,
                'status' => PaymentReceiptEmailDelivery::STATUS_FAILED,
                'error_message' => mb_substr($throwable->getMessage(), 0, 2000),
            ]);
        }

        return $delivery->fresh();
    }

    private function resolveEmail(PaymentReceipt $receipt, ?string $recipientEmail): ?string
    {
        $email = trim((string) ($recipientEmail ?: $receipt->client_email ?: $receipt->client?->email));

        return filter_var($email, FILTER_VALIDATE_EMAIL) ? $email : null;
    }
}
