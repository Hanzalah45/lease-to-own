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
        'term_months',
        'start_date',
        'renewal_date',
        'payment_due_day',
        'autopay_enabled',
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
        'updated_by',
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
            'autopay_enabled' => 'boolean',
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

    public function updatedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'updated_by');
    }

    /** The currently active signature, if any — a voided one never counts, which is what clears the way to sign again. */
    public function contract(): HasOne
    {
        return $this->hasOne(Contract::class)->whereNull('voided_at');
    }

    /** Full signature history, voided or not, newest first — for admin audit views. */
    public function contracts(): HasMany
    {
        return $this->hasMany(Contract::class)->latest();
    }

    public function payments(): HasMany
    {
        return $this->hasMany(Payment::class);
    }

    /**
     * Uses the already-loaded `payments` collection when available instead of
     * a fresh COUNT query — callers that list many leases eager-load payments
     * once up front, and this is called (often twice, via epoToday()) per
     * lease in that list, so re-querying here turns one query into hundreds.
     */
    public function paymentsMadeCount(): int
    {
        if ($this->relationLoaded('payments')) {
            return $this->payments->where('status', Payment::STATUS_PAID)->count();
        }

        return $this->payments()->where('status', Payment::STATUS_PAID)->count();
    }

    public function salesTaxAmount(): float
    {
        return round((float) $this->monthly_rental_payment * (float) $this->sales_tax_rate, 2);
    }

    public function totalMonthlyPayment(): float
    {
        return round((float) $this->monthly_rental_payment + $this->salesTaxAmount(), 2);
    }
}
