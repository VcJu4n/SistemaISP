<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ServiceHistory extends Model
{
    protected $fillable = ['internet_service_id', 'user_id', 'event_type', 'description', 'metadata', 'occurred_at'];

    protected function casts(): array
    {
        return ['metadata' => 'array', 'occurred_at' => 'datetime'];
    }

    public function service(): BelongsTo { return $this->belongsTo(InternetService::class, 'internet_service_id'); }
    public function user(): BelongsTo { return $this->belongsTo(User::class); }
}
