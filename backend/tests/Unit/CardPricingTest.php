<?php

namespace Tests\Unit;

use App\Services\CardPricing;
use Tests\TestCase;

/**
 * Dual pricing (client, Joel, 2026-10-05): card = bank price + 3%. Whole
 * cents, half-up, priced per CHARGE.
 */
class CardPricingTest extends TestCase
{
    public function test_the_default_rate_is_three_percent(): void
    {
        $this->assertSame(300, CardPricing::rateBps());
        $this->assertSame(3.0, CardPricing::ratePercent());
    }

    public function test_the_card_fee_is_three_percent_of_the_bank_price(): void
    {
        $this->assertSame(900, CardPricing::feeCents(30000)); // $300.00 -> $9.00
        $this->assertSame(1092, CardPricing::feeCents(36400)); // $364.00 -> $10.92
        $this->assertSame(30900, CardPricing::cardCents(30000));
        $this->assertSame(309.0, CardPricing::card(300.0));
    }

    public function test_the_fee_rounds_half_up_to_the_cent(): void
    {
        $this->assertSame(0, CardPricing::feeCents(1));   // 0.03 cents -> 0
        $this->assertSame(2, CardPricing::feeCents(50));  // 1.5 cents -> 2
        $this->assertSame(1, CardPricing::feeCents(49));  // 1.47 cents -> 1
        $this->assertSame(4, CardPricing::feeCents(149)); // 4.47 cents -> 4
        $this->assertSame(5, CardPricing::feeCents(150)); // 4.5 cents -> 5
        $this->assertSame(8, CardPricing::feeCents(250)); // 7.5 cents -> 8
    }

    public function test_a_bank_payment_never_carries_a_fee(): void
    {
        $this->assertSame(0, CardPricing::feeCentsFor(30000, 'bank'));
        $this->assertSame(900, CardPricing::feeCentsFor(30000, 'card'));
    }

    public function test_it_is_priced_per_charge_not_per_line_item(): void
    {
        // Two 50-cent charges each round their own fee up to 2 cents (4 total),
        // while one 100-cent charge is 3 cents. What is shown must equal what
        // is charged, so each real charge is priced on its own.
        $this->assertSame(2, (int) round(CardPricing::both(0.50)['card_fee'] * 100));
        $this->assertSame(3, (int) round(CardPricing::both(1.00)['card_fee'] * 100));
    }

    public function test_both_prices_for_an_amount(): void
    {
        $this->assertSame(['bank' => 214.0, 'card' => 220.42, 'card_fee' => 6.42], CardPricing::both(214.0));
    }

    public function test_the_rate_can_be_changed_without_code(): void
    {
        config(['billing.card_fee_bps' => 250]);

        $this->assertSame(750, CardPricing::feeCents(30000)); // 2.5% of $300
        $this->assertSame(2.5, CardPricing::ratePercent());
    }
}
