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
        'client_id', 'plan_id', 'status', 'installation_date', 'notes',
        'suspended_at', 'suspension_reason', 'suspension_notes',
    ];

    protected function casts(): array
    {
        return [
            'installation_date' => 'date:Y-m-d',
            'suspended_at' => 'datetime',
        ];
    }

    public function client(): BelongsTo { return $this->belongsTo(Client::class); }
    public function plan(): BelongsTo { return $this->belongsTo(Plan::class); }
    public function histories(): HasMany { return $this->hasMany(ServiceHistory::class)->latest('occurred_at'); }
}
