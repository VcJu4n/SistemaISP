<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

class Plan extends Model
{
    /** @use HasFactory<\Database\Factories\PlanFactory> */
    use HasFactory;

    protected $fillable = [
        'name', 'download_mbps', 'upload_mbps', 'monthly_price', 'description', 'active',
    ];

    protected function casts(): array
    {
        return [
            'active' => 'boolean',
            'monthly_price' => 'decimal:2',
        ];
    }

    public function zones(): BelongsToMany
    {
        return $this->belongsToMany(Zone::class);
    }
}
