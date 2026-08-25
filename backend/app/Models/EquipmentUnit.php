<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class EquipmentUnit extends Model
{
    use HasFactory;

    public const STATUS_IN_STOCK = 'in_stock';
    public const STATUS_LEASED = 'leased';
    public const STATUS_RETURNED = 'returned';
    public const STATUS_OWNED_BY_CUSTOMER = 'owned_by_customer';

    protected $fillable = [
        'model',
        'serial_number',
        'vin',
        'condition_notes',
        'delivery_date',
        'expected_return_or_ownership_date',
        'status',
        'gps_device_id',
    ];

    protected function casts(): array
    {
        return [
            'delivery_date' => 'date',
            'expected_return_or_ownership_date' => 'date',
        ];
    }

    public function leaseAgreements(): HasMany
    {
        return $this->hasMany(LeaseAgreement::class);
    }

    public function serviceRecords(): HasMany
    {
        return $this->hasMany(EquipmentServiceRecord::class);
    }

    /** Reserved for Phase 2 GPS provider integration. */
    public function gpsEvents(): HasMany
    {
        return $this->hasMany(GpsEvent::class);
    }
}
