<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Reserved for Phase 2 (live GPS provider integration). Not written to in Phase 1. */
class GpsEvent extends Model
{
    protected $fillable = [
        'equipment_unit_id',
        'event_type',
        'latitude',
        'longitude',
        'anomaly_type',
        'recorded_at',
    ];

    protected function casts(): array
    {
        return [
            'recorded_at' => 'datetime',
        ];
    }

    public function equipmentUnit(): BelongsTo
    {
        return $this->belongsTo(EquipmentUnit::class);
    }
}
