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
}
