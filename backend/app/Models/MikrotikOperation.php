<?php

namespace App\Models;

use Database\Factories\MikrotikOperationFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class MikrotikOperation extends Model
{
    /** @use HasFactory<MikrotikOperationFactory> */
    use HasFactory;

    public const ACTION_CREATE_ACCESS = 'create_access';

    public const ACTION_SUSPEND = 'suspend';

    public const ACTION_REACTIVATE = 'reactivate';

    public const ACTION_CHANGE_PLAN = 'change_plan';

    public const STATUS_PENDING = 'pending';

    public const STATUS_PROCESSING = 'processing';

    public const STATUS_SYNCED = 'synced';

    public const STATUS_FAILED = 'failed';

    public const ACTIONS = [
        self::ACTION_CREATE_ACCESS,
        self::ACTION_SUSPEND,
        self::ACTION_REACTIVATE,
        self::ACTION_CHANGE_PLAN,
    ];

    public const STATUSES = [
        self::STATUS_PENDING,
        self::STATUS_PROCESSING,
        self::STATUS_SYNCED,
        self::STATUS_FAILED,
    ];

    protected $fillable = [
        'internet_service_id',
        'mikrotik_router_id',
        'action',
        'status',
        'attempts',
        'payload',
        'last_error',
        'last_attempt_at',
        'synced_at',
    ];

    protected function casts(): array
    {
        return [
            'payload' => 'array',
            'last_attempt_at' => 'datetime',
            'synced_at' => 'datetime',
        ];
    }

    public function service(): BelongsTo
    {
        return $this->belongsTo(InternetService::class, 'internet_service_id');
    }

    public function router(): BelongsTo
    {
        return $this->belongsTo(MikrotikRouter::class, 'mikrotik_router_id');
    }

    public function markProcessing(): void
    {
        $this->update([
            'status' => self::STATUS_PROCESSING,
            'attempts' => $this->attempts + 1,
            'last_error' => null,
            'last_attempt_at' => now(),
            'synced_at' => null,
        ]);
    }

    public function markSynced(): void
    {
        $this->update([
            'status' => self::STATUS_SYNCED,
            'last_error' => null,
            'synced_at' => now(),
        ]);
    }

    public function markFailed(string $error): void
    {
        $this->update([
            'status' => self::STATUS_FAILED,
            'last_error' => $error,
            'last_attempt_at' => now(),
            'synced_at' => null,
        ]);
    }

    public function retry(): void
    {
        $this->update([
            'status' => self::STATUS_PENDING,
            'attempts' => 0,
            'last_error' => null,
            'last_attempt_at' => null,
            'synced_at' => null,
        ]);
    }
}
