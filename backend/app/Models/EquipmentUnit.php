<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class EquipmentUnit extends Model
{
    use HasFactory;

    public const STATUS_IN_STOCK = 'in_stock';
    public const STATUS_LEASED = 'leased';
    public const STATUS_RETURNED = 'returned';
    public const STATUS_OWNED_BY_CUSTOMER = 'owned_by_customer';

    /** Every status a unit can hold — drives validation and the admin filter bar. */
    public const STATUSES = [
        self::STATUS_IN_STOCK,
        self::STATUS_LEASED,
        self::STATUS_RETURNED,
        self::STATUS_OWNED_BY_CUSTOMER,
    ];

    /**
     * Statuses that leave a unit free to hand to a new lease. A `leased` unit
     * is on someone else's lease and `owned_by_customer` has left the fleet,
     * so neither can be assigned again without being released first.
     */
    public const ASSIGNABLE_STATUSES = [
        self::STATUS_IN_STOCK,
        self::STATUS_RETURNED,
    ];

    protected $fillable = [
        'model',
        'serial_number',
        'vin',
        'condition_notes',
        'delivery_date',
        'expected_return_or_ownership_date',
        'status',
        'gps_device_id',
        'updated_by',
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

    /**
     * The lease this unit is sitting on right now. A unit can be leased,
     * returned and leased again, so "current" is simply the newest lease
     * still pointing at it.
     */
    public function currentLease(): HasOne
    {
        return $this->hasOne(LeaseAgreement::class)->latestOfMany();
    }

    public function serviceRecords(): HasMany
    {
        return $this->hasMany(EquipmentServiceRecord::class);
    }

    public function updatedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'updated_by');
    }

    /** Reserved for Phase 2 GPS provider integration. */
    public function gpsEvents(): HasMany
    {
        return $this->hasMany(GpsEvent::class);
    }

    public function isAssignable(): bool
    {
        return in_array($this->status, self::ASSIGNABLE_STATUSES, true);
    }

    /** Serial-number-first lookup: serial is the primary identifier, model and VIN are fallbacks. */
    public function scopeSearch(Builder $query, ?string $term): Builder
    {
        $term = trim((string) $term);

        if ($term === '') {
            return $query;
        }

        return $query->where(function (Builder $q) use ($term) {
            $q->where('serial_number', 'like', "%{$term}%")
                ->orWhere('model', 'like', "%{$term}%")
                ->orWhere('vin', 'like', "%{$term}%");
        });
    }
}
