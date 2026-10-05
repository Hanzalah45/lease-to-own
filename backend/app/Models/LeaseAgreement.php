<?php

namespace App\Models;

use App\Services\CardPricing;
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

    /**
     * Flat GPS tracking device fee (client's official pricing blueprint,
     * 2026-09-04) — due at signing alongside the deposit, but a SEPARATE
     * line item from it, not folded in. Same for every lease, so this is a
     * constant rather than a persisted column.
     */
    public const TRACKING_DEVICE_FEE = 150.0;

    protected $fillable = [
        'application_id',
        'customer_id',
        'equipment_unit_id',
        'term_months',
        'start_date',
        'renewal_date',
        'payment_due_day',
        'billing_cycle',
        'autopay_enabled',
        'autopay_paused_at',
        'stripe_bank_payment_method_id',
        'stripe_card_payment_method_id',
        'autopay_primary_method',
        'payment_methods_override_by',
        'payment_methods_override_at',
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
            'autopay_paused_at' => 'datetime',
            'payment_methods_override_at' => 'datetime',
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

    public function paymentMethodsOverrideBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'payment_methods_override_by');
    }

    /** Both a bank account and a card must be on file before AutoPay has anything to fall back on. */
    public function hasBothAutopayMethods(): bool
    {
        return (bool) $this->stripe_bank_payment_method_id && (bool) $this->stripe_card_payment_method_id;
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
     *
     * Scoped to type=rental only (tightened 2026-10-02 when pickup_balance was
     * added — the prior "!= deposit" denylist would otherwise have let a paid
     * pickup_balance row inflate this too, the same bug a paid deposit row
     * was fixed for on 2026-10-01). A paid deposit or pickup_balance row must
     * never count toward "months paid" — it would inflate
     * rental_payments_paid_to_date, flip ownership_status to OWNED early, and
     * understate the EPO price. late_fee is excluded by the same allowlist;
     * no behavior change intended there, it simply was never counted as a
     * rental payment either.
     */
    public function paymentsMadeCount(): int
    {
        if ($this->relationLoaded('payments')) {
            return $this->payments->where('status', Payment::STATUS_PAID)->where('type', Payment::TYPE_RENTAL)->count();
        }

        return $this->payments()->where('status', Payment::STATUS_PAID)->where('type', Payment::TYPE_RENTAL)->count();
    }

    /**
     * The recurring LDW charge when ldw_selected is true, or 0 when it's
     * false — declining LDW carries no surcharge (see
     * ApplicationCreationService::buildEquipmentAndLease).
     */
    public function ldwMonthlyAmount(): float
    {
        return round((float) ($this->ldw_amount ?? 0), 2);
    }

    public function salesTaxAmount(): float
    {
        return round(((float) $this->monthly_rental_payment + $this->ldwMonthlyAmount()) * (float) $this->sales_tax_rate, 2);
    }

    public function totalMonthlyPayment(): float
    {
        return round((float) $this->monthly_rental_payment + $this->ldwMonthlyAmount() + $this->salesTaxAmount(), 2);
    }

    /** The security deposit alone — what "pay deposit only" charges now (client, Joel, 2026-10-02). */
    public function depositAmountDue(): float
    {
        return round((float) $this->security_deposit, 2);
    }

    /** Tracking fee + first month — what "pay deposit only" defers to a later, separate charge once the customer is ready for pickup. */
    public function pickupBalanceAmountDue(): float
    {
        return round(self::TRACKING_DEVICE_FEE + $this->totalMonthlyPayment(), 2);
    }

    /** What the customer owes at signing if paying in full: deposit + tracking fee + first month. Single source of truth — ContractPdfService reads this instead of re-deriving the formula. */
    public function totalDueAtSigning(): float
    {
        return round($this->depositAmountDue() + $this->pickupBalanceAmountDue(), 2);
    }

    /**
     * Dual pricing (client, Joel, 2026-10-05): every amount a customer pays,
     * at the bank price (exactly the stored lease numbers) and the card price
     * (bank price + the card fee). Priced per CHARGE, not per line item:
     * "balance" is the tracking fee + first month as ONE charge, and the card
     * "full" total is the sum of the two separately rounded charges, so the
     * number shown always equals what Stripe is asked to collect.
     *
     * @return array{card_fee_percent: float, deposit: array, pickup_balance: array, monthly: array, full: array}
     */
    public function pricingSummary(): array
    {
        $deposit = CardPricing::both($this->depositAmountDue());
        $balance = CardPricing::both($this->pickupBalanceAmountDue());

        return [
            'card_fee_percent' => CardPricing::ratePercent(),
            'deposit' => $deposit,
            'pickup_balance' => $balance,
            'monthly' => CardPricing::both($this->totalMonthlyPayment()),
            'full' => [
                'bank' => round($deposit['bank'] + $balance['bank'], 2),
                'card' => round($deposit['card'] + $balance['card'], 2),
                'card_fee' => round($deposit['card_fee'] + $balance['card_fee'], 2),
            ],
        ];
    }

    /**
     * The saved Stripe PaymentMethod to charge for an explicit choice
     * ('bank' or 'card'), or, with no choice, the customer's AutoPay primary
     * (see autopayChargeablePaymentMethod()). Null when that method isn't on file.
     *
     * @return array{type: string, id: string}|null
     */
    public function paymentMethodFor(?string $type): ?array
    {
        return match ($type) {
            'bank' => $this->stripe_bank_payment_method_id ? ['type' => 'bank', 'id' => $this->stripe_bank_payment_method_id] : null,
            'card' => $this->stripe_card_payment_method_id ? ['type' => 'card', 'id' => $this->stripe_card_payment_method_id] : null,
            default => $this->autopayChargeablePaymentMethod(),
        };
    }

    /**
     * Which Stripe PaymentMethod AutoPay (or a deposit charge) should use:
     * the customer's own chosen primary if it's actually on file, otherwise
     * whichever of bank/card exists — never both, and never neither without
     * returning null for the caller to handle.
     */
    public function autopayChargeablePaymentMethod(): ?array
    {
        if ($this->autopay_primary_method === 'ach' && $this->stripe_bank_payment_method_id) {
            return ['type' => 'bank', 'id' => $this->stripe_bank_payment_method_id];
        }
        if ($this->autopay_primary_method === 'card' && $this->stripe_card_payment_method_id) {
            return ['type' => 'card', 'id' => $this->stripe_card_payment_method_id];
        }
        if ($this->stripe_bank_payment_method_id) {
            return ['type' => 'bank', 'id' => $this->stripe_bank_payment_method_id];
        }
        if ($this->stripe_card_payment_method_id) {
            return ['type' => 'card', 'id' => $this->stripe_card_payment_method_id];
        }

        return null;
    }
}
