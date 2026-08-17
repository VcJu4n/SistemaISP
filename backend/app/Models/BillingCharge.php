<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class BillingCharge extends Model
{
    /** @use HasFactory<\Database\Factories\BillingChargeFactory> */
    use HasFactory;

    public const STATUS_PENDING = 'pending';
    public const STATUS_PARTIAL = 'partial';
    public const STATUS_PAID = 'paid';
    public const STATUS_OVERDUE = 'overdue';
    public const STATUS_CANCELLED = 'cancelled';

    protected $fillable = [
        'internet_service_id',
        'client_id',
        'created_by',
        'period_year',
        'period_month',
        'period_label',
        'issue_date',
        'due_date',
        'cutoff_date',
        'grace_deadline',
        'concept',
        'currency',
        'original_amount',
        'paid_amount',
        'balance',
        'status',
        'notes',
    ];

    protected function casts(): array
    {
        return [
            'period_year' => 'integer',
            'period_month' => 'integer',
            'issue_date' => 'date:Y-m-d',
            'due_date' => 'date:Y-m-d',
            'cutoff_date' => 'date:Y-m-d',
            'grace_deadline' => 'date:Y-m-d',
            'original_amount' => 'decimal:2',
            'paid_amount' => 'decimal:2',
            'balance' => 'decimal:2',
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

    public function receipts(): HasMany
    {
        return $this->hasMany(PaymentReceipt::class);
    }

    public function displayStatus(): string
    {
        if ((float) $this->balance <= 0 || $this->status === self::STATUS_PAID) {
            return self::STATUS_PAID;
        }

        if ($this->status === self::STATUS_CANCELLED) {
            return self::STATUS_CANCELLED;
        }

        if ($this->status === self::STATUS_PENDING && now()->toDateString() > $this->grace_deadline?->toDateString()) {
            return self::STATUS_OVERDUE;
        }

        if ($this->status === self::STATUS_PENDING && now()->toDateString() >= $this->due_date?->toDateString()) {
            return 'grace';
        }

        return $this->status;
    }

    public function displayLabel(): string
    {
        return match ($this->displayStatus()) {
            'paid' => 'Pagado',
            'partial' => 'Parcial',
            'overdue' => 'Vencido',
            'grace' => 'Perdonazo',
            'cancelled' => 'Anulado',
            default => 'Pendiente',
        };
    }
}
