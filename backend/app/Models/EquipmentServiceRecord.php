<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class EquipmentServiceRecord extends Model
{
    protected $fillable = [
        'equipment_unit_id',
        'service_date',
        'description',
        'performed_by',
    ];

    protected function casts(): array
    {
        return [
            'service_date' => 'date',
        ];
    }

    public function equipmentUnit(): BelongsTo
    {
        return $this->belongsTo(EquipmentUnit::class);
    }

    public function performedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'performed_by');
    }
}
