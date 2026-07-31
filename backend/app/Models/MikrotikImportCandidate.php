<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class MikrotikImportCandidate extends Model
{
    /** @use HasFactory<\Database\Factories\MikrotikImportCandidateFactory> */
    use HasFactory;

    public const SOURCE_PPPOE = 'pppoe';
    public const SOURCE_SIMPLE_QUEUE = 'simple_queue';
    public const SOURCE_DHCP_MAC = 'dhcp_mac';
    public const SOURCE_HOTSPOT = 'hotspot';

    public const STATUS_UNLINKED = 'unlinked';
    public const STATUS_LINKED = 'linked';
    public const STATUS_IGNORED = 'ignored';

    public const SOURCES = [
        self::SOURCE_PPPOE,
        self::SOURCE_SIMPLE_QUEUE,
        self::SOURCE_DHCP_MAC,
        self::SOURCE_HOTSPOT,
    ];

    public const STATUSES = [
        self::STATUS_UNLINKED,
        self::STATUS_LINKED,
        self::STATUS_IGNORED,
    ];

    protected $fillable = [
        'mikrotik_router_id',
        'client_id',
        'internet_service_id',
        'source_type',
        'external_id',
        'identifier',
        'display_name',
        'ip_address',
        'mac_address',
        'profile',
        'rate_limit',
        'status',
        'raw_payload',
        'last_seen_at',
    ];

    protected function casts(): array
    {
        return [
            'raw_payload' => 'array',
            'last_seen_at' => 'datetime',
        ];
    }

    public function router(): BelongsTo
    {
        return $this->belongsTo(MikrotikRouter::class, 'mikrotik_router_id');
    }

    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class);
    }

    public function internetService(): BelongsTo
    {
        return $this->belongsTo(InternetService::class);
    }

    public function isServiceImportable(): bool
    {
        return in_array($this->source_type, [self::SOURCE_PPPOE, self::SOURCE_SIMPLE_QUEUE], true);
    }
}
