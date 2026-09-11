<?php

namespace Tests\Feature;

use App\Models\AdminPermission;
use App\Models\LeaseAgreement;
use App\Models\Payment;
use App\Models\User;
use App\Notifications\LateFeeChargedNotification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

/**
 * Client, 2026-09-10: automatically charge the contract's Late Fee (Section
 * 8) once a Rental Payment sits pending 10+ days past its due date — 10% of
 * the payment, floored at $5, capped at $30, once per missed payment.
 * payments:charge-late-fees runs daily (see ChargeLateFees).
 */
class LateFeeChargeTest extends TestCase
{
    use RefreshDatabase;

    private function overduePayment(float $amount = 200, int $daysPastDue = 11): Payment
    {
        $customer = User::factory()->create(['role' => User::ROLE_CUSTOMER]);
        $lease = LeaseAgreement::factory()->create(['customer_id' => $customer->id]);

        return Payment::factory()->create([
            'lease_agreement_id' => $lease->id,
            'amount' => $amount,
            'due_date' => now()->subDays($daysPastDue),
            'status' => Payment::STATUS_PENDING,
        ]);
    }

    public function test_a_payment_overdue_by_ten_or_more_days_is_charged_a_late_fee(): void
    {
        Notification::fake();
        $payment = $this->overduePayment(amount: 200, daysPastDue: 11);

        $this->artisan('payments:charge-late-fees')->assertSuccessful();

        $lateFee = Payment::where('late_fee_for_payment_id', $payment->id)->first();
        $this->assertNotNull($lateFee);
        $this->assertSame(Payment::TYPE_LATE_FEE, $lateFee->type);
        $this->assertSame(Payment::STATUS_PENDING, $lateFee->status);
        $this->assertSame('20.00', $lateFee->amount); // 10% of 200, within $5-$30

        $customer = $payment->leaseAgreement->customer;
        Notification::assertSentTo($customer, LateFeeChargedNotification::class);
    }

    public function test_the_fee_is_floored_at_five_dollars(): void
    {
        Notification::fake();
        $payment = $this->overduePayment(amount: 20, daysPastDue: 11); // 10% = $2, below the $5 floor

        $this->artisan('payments:charge-late-fees')->assertSuccessful();

        $lateFee = Payment::where('late_fee_for_payment_id', $payment->id)->first();
        $this->assertSame('5.00', $lateFee->amount);
    }

    public function test_the_fee_is_capped_at_thirty_dollars(): void
    {
        Notification::fake();
        $payment = $this->overduePayment(amount: 500, daysPastDue: 11); // 10% = $50, above the $30 cap

        $this->artisan('payments:charge-late-fees')->assertSuccessful();

        $lateFee = Payment::where('late_fee_for_payment_id', $payment->id)->first();
        $this->assertSame('30.00', $lateFee->amount);
    }

    public function test_a_payment_only_nine_days_past_due_is_left_alone(): void
    {
        Notification::fake();
        $this->overduePayment(daysPastDue: 9);

        $this->artisan('payments:charge-late-fees')->assertSuccessful();

        $this->assertSame(0, Payment::where('type', Payment::TYPE_LATE_FEE)->count());
        Notification::assertNothingSent();
    }

    public function test_an_already_paid_payment_is_not_charged_a_late_fee(): void
    {
        Notification::fake();
        $customer = User::factory()->create(['role' => User::ROLE_CUSTOMER]);
        $lease = LeaseAgreement::factory()->create(['customer_id' => $customer->id]);
        Payment::factory()->create([
            'lease_agreement_id' => $lease->id,
            'amount' => 200,
            'due_date' => now()->subDays(20),
            'status' => Payment::STATUS_PAID,
            'paid_date' => now()->subDays(15),
        ]);

        $this->artisan('payments:charge-late-fees')->assertSuccessful();

        $this->assertSame(0, Payment::where('type', Payment::TYPE_LATE_FEE)->count());
        Notification::assertNothingSent();
    }

    public function test_the_same_overdue_payment_is_not_charged_twice(): void
    {
        Notification::fake();
        $payment = $this->overduePayment(daysPastDue: 15);

        $this->artisan('payments:charge-late-fees')->assertSuccessful();
        $this->artisan('payments:charge-late-fees')->assertSuccessful();

        $this->assertSame(1, Payment::where('late_fee_for_payment_id', $payment->id)->count());
    }

    public function test_a_late_fee_row_itself_does_not_get_a_late_fee(): void
    {
        Notification::fake();
        $customer = User::factory()->create(['role' => User::ROLE_CUSTOMER]);
        $lease = LeaseAgreement::factory()->create(['customer_id' => $customer->id]);
        Payment::factory()->create([
            'lease_agreement_id' => $lease->id,
            'type' => Payment::TYPE_LATE_FEE,
            'amount' => 20,
            'due_date' => now()->subDays(20),
            'status' => Payment::STATUS_PENDING,
        ]);

        $this->artisan('payments:charge-late-fees')->assertSuccessful();

        $this->assertSame(1, Payment::count());
        Notification::assertNothingSent();
    }

    /**
     * Real bug found this session: Mailtrap's sandbox rate limit crashed this
     * exact command mid-loop on local (the same failure mode flagged earlier
     * for contract signing). A mail transport hiccup on one customer must not
     * abort the run and leave every other overdue customer's fee uncharged.
     */
    public function test_the_fee_is_still_charged_even_if_the_notification_fails_to_send(): void
    {
        Mail::shouldReceive('send')->andThrow(new \RuntimeException('SMTP transport failure (simulated)'));
        $payment = $this->overduePayment(daysPastDue: 15);

        $this->artisan('payments:charge-late-fees')->assertSuccessful();

        $lateFee = Payment::where('late_fee_for_payment_id', $payment->id)->first();
        $this->assertNotNull($lateFee);
    }

    public function test_payment_tracking_staff_are_notified(): void
    {
        Notification::fake();
        $superAdmin = User::factory()->create(['role' => User::ROLE_SUPER_ADMIN]);
        $unrestrictedAdmin = User::factory()->create(['role' => User::ROLE_ADMIN]);
        $restrictedAdmin = User::factory()->create(['role' => User::ROLE_ADMIN]);
        AdminPermission::create(['user_id' => $restrictedAdmin->id, 'permission' => AdminPermission::APPLICATION_REVIEW]);

        $this->overduePayment(daysPastDue: 15);

        $this->artisan('payments:charge-late-fees')->assertSuccessful();

        Notification::assertSentTo($superAdmin, LateFeeChargedNotification::class);
        Notification::assertSentTo($unrestrictedAdmin, LateFeeChargedNotification::class);
        Notification::assertNotSentTo($restrictedAdmin, LateFeeChargedNotification::class);
    }
}
