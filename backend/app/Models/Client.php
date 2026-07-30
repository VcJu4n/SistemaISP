<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;

class Client extends Model
{
    /** @use HasFactory<\Database\Factories\ClientFactory> */
    use HasFactory, SoftDeletes;

    protected $fillable = [
        'full_name',
        'document',
        'phone',
        'email',
        'address',
        'zone_id',
        'installation_date',
        'status',
    ];

    protected function casts(): array
    {
        return [
            'installation_date' => 'date:Y-m-d',
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
