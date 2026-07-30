<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class MikrotikRouter extends Model
{
    /** @use HasFactory<\Database\Factories\MikrotikRouterFactory> */
    use HasFactory;

    public const STATUS_PENDING = 'pending';
    public const STATUS_CONNECTED = 'connected';
    public const STATUS_DISCONNECTED = 'disconnected';

    public const CONNECTION_STATUSES = [
        self::STATUS_PENDING,
        self::STATUS_CONNECTED,
        self::STATUS_DISCONNECTED,
    ];

    protected $fillable = [
        'name',
        'ip_address',
        'api_port',
        'username',
        'password',
        'use_ssl',
        'active',
        'connection_status',
        'last_successful_connection_at',
        'last_error',
    ];

    protected $hidden = ['password'];

    protected function casts(): array
    {
        return [
            'password' => 'encrypted',
            'use_ssl' => 'boolean',
            'active' => 'boolean',
            'last_successful_connection_at' => 'datetime',
        ];
    }

    public function services(): HasMany
    {
        return $this->hasMany(InternetService::class, 'mikrotik_router_id');
    }

    public function operations(): HasMany
    {
        return $this->hasMany(MikrotikOperation::class, 'mikrotik_router_id');
    }
}
