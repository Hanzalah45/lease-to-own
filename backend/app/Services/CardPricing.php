<?php

namespace App\Services;

/**
 * Dual pricing (client, Joel, 2026-10-05): the same amount costs more by card
 * than by bank/ACH. Every stored lease number is the BANK price; the card
 * price is that plus a percentage (config/billing.php, 3% to start). The
 * extra is a processing cost, so it is kept in its own column
 * (payments.card_fee_amount) and never touches ownership, EPO or the
 * paid-to-date figure.
 *
 * All arithmetic is in whole cents with half-up rounding. The fee is priced
 * per CHARGE (the amount Stripe is actually asked to collect), never per
 * line item, so what the customer is shown always equals what is charged.
 */
class CardPricing
{
    public const METHOD_BANK = 'bank';

    public const METHOD_CARD = 'card';

    public static function rateBps(): int
    {
        return (int) config('billing.card_fee_bps', 300);
    }

    /** The fee as a human percentage, e.g. 3.0 for 300 bps. */
    public static function ratePercent(): float
    {
        return self::rateBps() / 100;
    }

    public static function toCents(float $amount): int
    {
        return (int) round($amount * 100);
    }

    /** Card fee on a bank-price amount, in cents (half-up). */
    public static function feeCents(int $bankCents): int
    {
        return intdiv($bankCents * self::rateBps() + 5000, 10000);
    }

    public static function cardCents(int $bankCents): int
    {
        return $bankCents + self::feeCents($bankCents);
    }

    /** The card fee in cents for a charge made with the given method ('bank' pays none). */
    public static function feeCentsFor(int $bankCents, string $methodType): int
    {
        return $methodType === self::METHOD_CARD ? self::feeCents($bankCents) : 0;
    }

    public static function fee(float $bankAmount): float
    {
        return self::feeCents(self::toCents($bankAmount)) / 100.0;
    }

    public static function card(float $bankAmount): float
    {
        return self::cardCents(self::toCents($bankAmount)) / 100.0;
    }

    /**
     * Both prices for one chargeable amount.
     *
     * @return array{bank: float, card: float, card_fee: float}
     */
    public static function both(float $bankAmount): array
    {
        return [
            'bank' => round($bankAmount, 2),
            'card' => self::card($bankAmount),
            'card_fee' => self::fee($bankAmount),
        ];
    }
}
