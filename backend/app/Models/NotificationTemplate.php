<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class NotificationTemplate extends Model
{
    public const PAYMENT_DUE_5 = 'payment_due_5';
    public const PAYMENT_DUE_2 = 'payment_due_2';
    public const PAYMENT_DUE_TODAY = 'payment_due_today';
    public const SUSPENDED = 'suspended';

    public const KEYS = [
        self::PAYMENT_DUE_5,
        self::PAYMENT_DUE_2,
        self::PAYMENT_DUE_TODAY,
        self::SUSPENDED,
    ];

    protected $fillable = ['key', 'name', 'body', 'active'];

    protected function casts(): array
    {
        return ['active' => 'boolean'];
    }

    public function logs(): HasMany
    {
        return $this->hasMany(NotificationLog::class);
    }
}
