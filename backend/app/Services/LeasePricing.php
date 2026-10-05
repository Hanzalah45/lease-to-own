<?php

namespace App\Services;

use App\Models\LeaseAgreement;

/**
 * The one place the lease pricing formulas live (client's official pricing
 * blueprint, 2026-09-04): used when an application's lease is created, when an
 * admin changes the mower on an existing application, and by the standalone
 * price calculator, so a number quoted to a customer is exactly the number the
 * system will store and bill. Mirrors the admin wizard's computeLeasePricing()
 * (frontend/src/components/applications/wizard/types.ts).
 *
 *   Base monthly   = cash price / divisor (12/24/36 months -> 10.0 / 16.0 / 19.8)
 *   Taking LDW     = +0.75% of cash price per month; deposit = 7% of cash price
 *   Declining LDW  = no surcharge; deposit = 3x the monthly payment
 *   Tracking fee   = flat $150, separate from the deposit
 *   Card price     = bank price + 3% per charge (see CardPricing)
 */
class LeasePricing
{
    /** The only terms with a defined monthly-payment divisor. */
    public const TERMS = [12, 24, 36];

    /**
     * Upper bound for a cash price: well above any mower, and far below what
     * the decimal(10,2) columns hold, so a typo is rejected instead of stored.
     */
    public const CASH_PRICE_MAX = 1000000;

    /**
     * Official divisor table from Prostart Leasing's customer-facing lease terms
     * sheet (2026-09-04). These are NOT proportional to the term, so it is a
     * lookup, not a formula.
     */
    public static function monthlyPaymentDivisor(int $termMonths): float
    {
        return match ($termMonths) {
            12 => 10.0,
            24 => 16.0,
            36 => 19.8,
            default => throw new \InvalidArgumentException("Unsupported lease term: {$termMonths} months. Only 12, 24, or 36 are priced."),
        };
    }

    /**
     * The lease columns derived from cash price, term and LDW. Monthly rental
     * and security deposit are never admin-typed, always computed here.
     *
     * @return array{monthly_rental_payment: float, ldw_amount: float, security_deposit: float, total_rental_purchase_price: float}
     */
    public static function terms(float $cashPrice, int $termMonths, bool $ldwSelected): array
    {
        $monthlyRental = round($cashPrice / self::monthlyPaymentDivisor($termMonths), 2);

        // Declining LDW adds no surcharge (client, 2026-09-15: the blueprint's
        // 0.35%/month figure was a typo). ldw_amount holds the LDW charge
        // when taken, 0 when declined.
        $ldwAmount = $ldwSelected ? round($cashPrice * 0.0075, 2) : 0.0;

        $securityDeposit = $ldwSelected
            ? round($cashPrice * 0.07, 2)
            : round(($monthlyRental + $ldwAmount) * 3, 2);

        return [
            'monthly_rental_payment' => $monthlyRental,
            'ldw_amount' => $ldwAmount,
            'security_deposit' => $securityDeposit,
            'total_rental_purchase_price' => LeaseEngine::totalRentalPurchasePrice($monthlyRental, $termMonths),
        ];
    }

    /**
     * Full quote for the price calculator and the change-mower preview: every
     * figure a customer is shown, at the bank price and the card price, built
     * from an unsaved lease so it runs through the very same model methods the
     * real lease uses.
     *
     * @param  float  $taxRatePercent  sales tax as a percent, e.g. 8.25
     * @return array<string, mixed>
     */
    public static function quote(float $cashPrice, float $taxRatePercent, int $termMonths, bool $ldwSelected): array
    {
        $terms = self::terms($cashPrice, $termMonths, $ldwSelected);

        $lease = new LeaseAgreement(array_merge($terms, [
            'term_months' => $termMonths,
            'cash_price' => $cashPrice,
            'sales_tax_rate' => $taxRatePercent / 100,
            'ldw_selected' => $ldwSelected,
            'additional_funds' => 0,
        ]));

        return [
            'cash_price' => round($cashPrice, 2),
            'term_months' => $termMonths,
            'tax_rate' => $taxRatePercent,
            'ldw_selected' => $ldwSelected,
            'monthly_rental' => $terms['monthly_rental_payment'],
            'ldw_amount' => $terms['ldw_amount'],
            'sales_tax' => $lease->salesTaxAmount(),
            'total_monthly_payment' => $lease->totalMonthlyPayment(),
            'security_deposit' => $terms['security_deposit'],
            'tracking_device_fee' => LeaseAgreement::TRACKING_DEVICE_FEE,
            'total_due_today' => $lease->totalDueAtSigning(),
            'total_rental_purchase_price' => $terms['total_rental_purchase_price'],
            'epo_today' => LeaseEngine::epoAt($lease, 1),
            'epo_schedule' => self::epoPreview($lease),
            'pricing' => $lease->pricingSummary(),
        ];
    }

    /**
     * Early Purchase Option payoff at month 1, every third month, and the
     * final month: the same points the wizard's payoff chart plots.
     *
     * @return array<int, array{month: int, value: float}>
     */
    private static function epoPreview(LeaseAgreement $lease): array
    {
        $term = (int) $lease->term_months;
        $months = [1];
        for ($m = 3; $m <= $term; $m += 3) {
            $months[] = $m;
        }
        if (end($months) !== $term) {
            $months[] = $term;
        }

        return array_map(fn (int $month) => ['month' => $month, 'value' => LeaseEngine::epoAt($lease, $month)], $months);
    }
}
