<?php

namespace App\Models;

use Database\Factories\ClientFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;

class Client extends Model
{
    /** @use HasFactory<ClientFactory> */
    use HasFactory, SoftDeletes;

    protected $fillable = [
        'full_name',
        'document',
        'phone',
        'email',
        'address',
        'location_reference',
        'latitude',
        'longitude',
        'zone_id',
        'installation_date',
        'status',
    ];

    protected function casts(): array
    {
        return [
            'installation_date' => 'date:Y-m-d',
            'latitude' => 'decimal:7',
            'longitude' => 'decimal:7',
        ];
    }

    public function zone(): BelongsTo
    {
        return $this->belongsTo(Zone::class);
    }

    public function internetService(): HasOne
    {
        return $this->hasOne(InternetService::class);
    }
}
