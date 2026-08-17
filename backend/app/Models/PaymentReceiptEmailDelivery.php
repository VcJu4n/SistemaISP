<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PaymentReceiptEmailDelivery extends Model
{
    /** @use HasFactory<\Database\Factories\PaymentReceiptEmailDeliveryFactory> */
    use HasFactory;

    public const STATUS_PENDING = 'pending';
    public const STATUS_SENT = 'sent';
    public const STATUS_FAILED = 'failed';

    protected $fillable = [
        'payment_receipt_id',
        'sent_by',
        'recipient_email',
        'status',
        'error_message',
        'sent_at',
    ];

    protected function casts(): array
    {
        return [
            'sent_at' => 'datetime',
        ];
    }

    public function receipt(): BelongsTo
    {
        return $this->belongsTo(PaymentReceipt::class, 'payment_receipt_id');
    }

    public function sender(): BelongsTo
    {
        return $this->belongsTo(User::class, 'sent_by');
    }
}
