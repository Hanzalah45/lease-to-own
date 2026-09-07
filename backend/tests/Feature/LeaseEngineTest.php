<?php

namespace Tests\Feature;

use App\Models\LeaseAgreement;
use App\Models\Payment;
use App\Services\LeaseEngine;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Covers a gap found by this session's audit: ownership_status flipped to
 * OWNED once every payment was paid, but never flipped back if a payment
 * was later corrected away from "paid" — leaving a lease permanently marked
 * owned despite no longer having enough paid installments on record.
 */
class LeaseEngineTest extends TestCase
{
    use RefreshDatabase;

    public function test_ownership_flips_to_owned_once_every_payment_is_paid(): void
    {
        $lease = LeaseAgreement::factory()->create(['term_months' => 2, 'monthly_rental_payment' => 100]);
        Payment::factory()->count(2)->create(['lease_agreement_id' => $lease->id, 'status' => Payment::STATUS_PAID]);

        LeaseEngine::syncPaymentsPaidToDate($lease);

        $this->assertSame(LeaseAgreement::OWNERSHIP_OWNED, $lease->fresh()->ownership_status);
    }

    public function test_ownership_reverts_to_leasing_if_a_paid_payment_is_corrected(): void
    {
        $lease = LeaseAgreement::factory()->create(['term_months' => 2, 'monthly_rental_payment' => 100]);
        $payments = Payment::factory()->count(2)->create(['lease_agreement_id' => $lease->id, 'status' => Payment::STATUS_PAID]);

        LeaseEngine::syncPaymentsPaidToDate($lease);
        $this->assertSame(LeaseAgreement::OWNERSHIP_OWNED, $lease->fresh()->ownership_status);

        // An admin corrects a mistaken "paid" mark back to failed.
        $payments->first()->update(['status' => Payment::STATUS_FAILED]);
        LeaseEngine::syncPaymentsPaidToDate($lease->fresh());

        $this->assertSame(LeaseAgreement::OWNERSHIP_LEASING, $lease->fresh()->ownership_status);
    }

    public function test_epo_within_90_days_is_cash_price_minus_payments_to_date(): void
    {
        $lease = LeaseAgreement::factory()->create([
            'term_months' => 24,
            'monthly_rental_payment' => 150,
            'cash_price' => 3000,
            'additional_funds' => 0,
        ]);

        // Month 3 is still within the 90-day cutoff.
        $this->assertSame(2550.0, LeaseEngine::epoAt($lease, 3));
    }

    public function test_epo_after_90_days_uses_50_percent_of_payments_scheduled_to_date(): void
    {
        // Final formula, client's direct answer 2026-09-05 (supersedes the
        // 2026-09-04 "full term scheduled" restatement, which could produce
        // an EPO exceeding the cash price and jumping discontinuously at
        // the 90-day mark): Cash Price − 50% of payments scheduled TO DATE
        // (not the full term) + still owed (0 here — no arrears) − additional funds.
        $lease = LeaseAgreement::factory()->create([
            'term_months' => 24,
            'monthly_rental_payment' => 150,
            'cash_price' => 3000,
            'additional_funds' => 100,
        ]);

        $month = 6;
        $scheduledToDate = $month * 150; // 900
        $expected = round(3000 - 0.5 * $scheduledToDate - 100, 2); // 2450.0
        $this->assertSame($expected, LeaseEngine::epoAt($lease, $month));

        // The 90-day boundary itself can still step up slightly (100%
        // credit/month before it vs. 50%/month after — an inherent, small
        // consequence of the formula, not a bug), but must not explode the
        // way the superseded "full term" version did (a ~$5,000 jump on
        // this exact lease). One month's worth of rental is a generous cap.
        $atCutoff = LeaseEngine::epoAt($lease, 3);
        $justAfter = LeaseEngine::epoAt($lease, 4);
        $this->assertLessThan(150, abs($justAfter - $atCutoff), 'EPO must not jump discontinuously across the 90-day mark.');

        $laterMonth = 12;
        $laterScheduledToDate = $laterMonth * 150; // 1800
        $laterExpected = round(3000 - 0.5 * $laterScheduledToDate - 100, 2);
        $this->assertSame($laterExpected, LeaseEngine::epoAt($lease, $laterMonth));
        $this->assertLessThan($expected, $laterExpected, 'EPO should decrease as more months pass.');
    }

    public function test_epo_today_adds_back_any_past_due_unpaid_amount(): void
    {
        // "Payments still owed" (client, 2026-09-05) means amounts already
        // past due and unpaid — $0 for a customer current on payments, but
        // real arrears must be added back so a delinquent customer can't
        // get a cheap EPO quote by simply not paying.
        $lease = LeaseAgreement::factory()->create([
            'term_months' => 24,
            'monthly_rental_payment' => 150,
            'cash_price' => 3000,
            'additional_funds' => 0,
        ]);

        // 5 months paid, but one of those 5 due dates is still unpaid (past due).
        Payment::factory()->count(4)->create([
            'lease_agreement_id' => $lease->id,
            'status' => Payment::STATUS_PAID,
            'due_date' => now()->subMonths(4),
        ]);
        Payment::factory()->create([
            'lease_agreement_id' => $lease->id,
            'status' => Payment::STATUS_FAILED,
            'due_date' => now()->subMonth(),
            'amount' => 150,
        ]);

        // month = paymentsMadeCount() = 4 (only the paid ones count).
        $expectedWithArrears = round(3000 - 0.5 * (4 * 150) + 150 - 0, 2);
        $this->assertSame($expectedWithArrears, LeaseEngine::epoToday($lease->fresh()));

        // A current customer (no past-due unpaid payment) gets the plain formula.
        $currentLease = LeaseAgreement::factory()->create([
            'term_months' => 24,
            'monthly_rental_payment' => 150,
            'cash_price' => 3000,
            'additional_funds' => 0,
        ]);
        Payment::factory()->count(4)->create([
            'lease_agreement_id' => $currentLease->id,
            'status' => Payment::STATUS_PAID,
            'due_date' => now()->subMonths(4),
        ]);
        $expectedCurrent = round(3000 - 0.5 * (4 * 150) - 0, 2);
        $this->assertSame($expectedCurrent, LeaseEngine::epoToday($currentLease->fresh()));
    }

    public function test_security_deposit_does_not_reduce_epo(): void
    {
        // Client, direct answer 2026-09-05: "the deposit does not apply to
        // the EPO. its considered to be the cost of the loan."
        $withDeposit = LeaseAgreement::factory()->create([
            'term_months' => 24,
            'monthly_rental_payment' => 150,
            'cash_price' => 3000,
            'security_deposit' => 500,
            'additional_funds' => 0,
        ]);
        $noDeposit = LeaseAgreement::factory()->create([
            'term_months' => 24,
            'monthly_rental_payment' => 150,
            'cash_price' => 3000,
            'security_deposit' => 0,
            'additional_funds' => 0,
        ]);

        $this->assertSame(LeaseEngine::epoAt($withDeposit, 6), LeaseEngine::epoAt($noDeposit, 6));
    }

    public function test_epo_is_zero_at_or_after_the_final_month(): void
    {
        $lease = LeaseAgreement::factory()->create([
            'term_months' => 12,
            'monthly_rental_payment' => 150,
            'cash_price' => 3000,
        ]);

        $this->assertSame(0.0, LeaseEngine::epoAt($lease, 12));
        $this->assertSame(0.0, LeaseEngine::epoAt($lease, 20));
    }
}
