<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class InternetService extends Model
{
    /** @use HasFactory<\Database\Factories\InternetServiceFactory> */
    use HasFactory;

    protected $fillable = [
        'client_id', 'plan_id', 'status', 'installation_date', 'next_due_date', 'notes',
        'mikrotik_router_id', 'mikrotik_control_method', 'pppoe_username',
        'pppoe_password', 'pppoe_profile', 'simple_queue_name', 'service_ip_address',
        'service_mac_address', 'client_antenna_ip', 'client_antenna_mac',
        'client_antenna_brand_model', 'client_antenna_device_name',
        'technical_notes',
        'suspended_at', 'suspension_reason', 'suspension_notes',
    ];

    protected $hidden = ['pppoe_password'];

    protected function casts(): array
    {
        return [
            'installation_date' => 'date:Y-m-d',
            'next_due_date' => 'date:Y-m-d',
            'suspended_at' => 'datetime',
            'pppoe_password' => 'encrypted',
        ];
    }

    public function client(): BelongsTo { return $this->belongsTo(Client::class); }
    public function plan(): BelongsTo { return $this->belongsTo(Plan::class); }
    public function mikrotikRouter(): BelongsTo { return $this->belongsTo(MikrotikRouter::class, 'mikrotik_router_id'); }
    public function histories(): HasMany { return $this->hasMany(ServiceHistory::class)->latest('occurred_at'); }
    public function mikrotikOperations(): HasMany { return $this->hasMany(MikrotikOperation::class); }
    public function payments(): HasMany { return $this->hasMany(Payment::class); }
    public function notificationLogs(): HasMany { return $this->hasMany(NotificationLog::class); }

    public function requiresMikrotikSync(): bool
    {
        return $this->mikrotik_router_id !== null || $this->mikrotik_control_method !== 'manual';
    }

    public function technicalConfig(): array
    {
        return [
            'control_method' => $this->mikrotik_control_method,
            'pppoe' => [
                'username' => $this->pppoe_username,
                'password_configured' => $this->pppoe_password !== null,
                'profile' => $this->pppoe_profile,
            ],
            'simple_queue' => [
                'name' => $this->simple_queue_name,
                'ip_address' => $this->service_ip_address,
                'max_limit' => $this->plan ? "{$this->plan->download_mbps}M/{$this->plan->upload_mbps}M" : null,
            ],
            'mac_address' => $this->service_mac_address,
            'antenna' => [
                'ip' => $this->client_antenna_ip,
                'mac' => $this->client_antenna_mac,
                'brand_model' => $this->client_antenna_brand_model,
                'device_name' => $this->client_antenna_device_name,
            ],
            'technical_notes' => $this->technical_notes,
        ];
    }
}
