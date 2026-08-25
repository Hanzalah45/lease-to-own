<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class RiskRedFlag extends Model
{
    protected $fillable = [
        'risk_profile_id',
        'type',
        'description',
        'flagged_at',
        'resolved',
    ];

    protected function casts(): array
    {
        return [
            'flagged_at' => 'datetime',
            'resolved' => 'boolean',
        ];
    }

    public function riskProfile(): BelongsTo
    {
        return $this->belongsTo(RiskProfile::class);
    }
}
