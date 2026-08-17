<?php

namespace App\Mail;

use App\Models\PaymentReceipt;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Mail\Mailable;
use Illuminate\Queue\SerializesModels;

class PaymentReceiptMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(public readonly PaymentReceipt $receipt) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: "Recibo de pago {$this->receipt->receipt_number} - {$this->receipt->client_name}",
        );
    }

    public function content(): Content
    {
        return new Content(
            view: 'emails.payment-receipt',
            with: [
                'receipt' => $this->receipt,
            ],
        );
    }
}
