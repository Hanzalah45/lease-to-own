<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class LeaseAgreement extends Model
{
    use HasFactory;

    public const OWNERSHIP_LEASING = 'leasing';
    public const OWNERSHIP_OWNED = 'owned';

    protected $fillable = [
        'application_id',
        'customer_id',
        'equipment_unit_id',
        'start_date',
        'renewal_date',
        'monthly_rental_payment',
        'sales_tax_rate',
        'security_deposit',
        'cash_price',
        'total_rental_purchase_price',
        'rental_payments_paid_to_date',
        'additional_funds',
        'ownership_status',
        'ldw_selected',
        'ldw_amount',
        'promo_code',
        'promo_discount',
    ];

    protected function casts(): array
    {
        return [
            'start_date' => 'date',
            'renewal_date' => 'date',
            'monthly_rental_payment' => 'decimal:2',
            'sales_tax_rate' => 'decimal:4',
            'security_deposit' => 'decimal:2',
            'cash_price' => 'decimal:2',
            'total_rental_purchase_price' => 'decimal:2',
            'rental_payments_paid_to_date' => 'decimal:2',
            'additional_funds' => 'decimal:2',
            'ldw_selected' => 'boolean',
            'ldw_amount' => 'decimal:2',
            'promo_discount' => 'decimal:2',
        ];
    }

    public function application(): BelongsTo
    {
        return $this->belongsTo(Application::class);
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'customer_id');
    }

    public function equipmentUnit(): BelongsTo
    {
        return $this->belongsTo(EquipmentUnit::class);
    }

    public function contract(): HasOne
    {
        return $this->hasOne(Contract::class);
    }

    public function payments(): HasMany
    {
        return $this->hasMany(Payment::class);
    }

    // Full-term and EPO pricing engine (Milestone 2) is intentionally not
    // implemented here yet — this model only carries the schema for it.
}
