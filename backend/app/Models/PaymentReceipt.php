<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class PaymentReceipt extends Model
{
    /** @use HasFactory<\Database\Factories\PaymentReceiptFactory> */
    use HasFactory;

    protected $fillable = [
        'receipt_number',
        'internet_service_id',
        'client_id',
        'created_by',
        'client_name',
        'client_document',
        'client_phone',
        'client_email',
        'plan_name',
        'payment_date',
        'cutoff_date',
        'billing_period',
        'concept',
        'observations',
        'currency',
        'subtotal',
        'iva_rate',
        'retention_rate',
        'iva',
        'retention',
        'total_received',
        'amount_words',
    ];

    protected function casts(): array
    {
        return [
            'payment_date' => 'date:Y-m-d',
            'cutoff_date' => 'date:Y-m-d',
            'subtotal' => 'decimal:2',
            'iva_rate' => 'decimal:2',
            'retention_rate' => 'decimal:2',
            'iva' => 'decimal:2',
            'retention' => 'decimal:2',
            'total_received' => 'decimal:2',
        ];
    }

    public function internetService(): BelongsTo
    {
        return $this->belongsTo(InternetService::class);
    }

    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function emailDeliveries(): HasMany
    {
        return $this->hasMany(PaymentReceiptEmailDelivery::class);
    }

    public function latestEmailDelivery(): HasOne
    {
        return $this->hasOne(PaymentReceiptEmailDelivery::class)->latestOfMany();
    }
}
