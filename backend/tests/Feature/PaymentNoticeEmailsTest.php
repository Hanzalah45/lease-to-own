<?php

namespace Tests\Feature;

use App\Models\LeaseAgreement;
use App\Models\Payment;
use App\Models\PaymentAttempt;
use App\Models\User;
use App\Notifications\PaymentOverdueNotification;
use App\Notifications\PaymentStatusChangedNotification;
use App\Services\LeaseEngine;
use App\Services\PaymentFailureReason;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

/**
 * The two emails a customer gets about a payment that did not happen (client, Joel, 2026-11-02, after his own insurance company
 * emailed him the moment his autopay failed): "your payment did not go through" when a charge fails, and "your payment has not been
 * received" the day after a due date was missed.
 */
class PaymentNoticeEmailsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        // Noon in Texas on Nov 16, so "yesterday" is the 15th whatever the server's UTC date is.
        $this->travelTo(Carbon::parse('2026-11-16 18:00:00', 'UTC'));
    }

    private function customer(): User
    {
        return User::factory()->create(['role' => User::ROLE_CUSTOMER, 'name' => 'Jane Doe']);
    }

    private function payment(User $customer, array $overrides = [], bool $pickedUp = true): Payment
    {
        $lease = $pickedUp
            ? LeaseAgreement::factory()->pickedUp()->create(['customer_id' => $customer->id])
            : LeaseAgreement::factory()->create(['customer_id' => $customer->id]);

        return Payment::factory()->create(array_merge([
            'lease_agreement_id' => $lease->id,
            'type' => Payment::TYPE_RENTAL,
            'amount' => 216.50,
            'status' => Payment::STATUS_PENDING,
            'due_date' => '2026-11-15',
        ], $overrides));
    }

    /** @return list<string> the lines of the email, in order */
    private function mailLines(object $notification, User $to): array
    {
        $mail = $notification->toMail($to);

        return [$mail->subject, ...array_map(fn ($l) => (string) $l, $mail->introLines), (string) $mail->actionText];
    }

    public function test_a_payment_that_did_not_go_through_tells_the_customer_why_what_to_do_and_what_happens_next(): void
    {
        $customer = $this->customer();
        $payment = $this->payment($customer, ['status' => Payment::STATUS_FAILED, 'method' => 'ach']);
        PaymentAttempt::create([
            'payment_id' => $payment->id, 'attempt_no' => 1, 'round' => 1, 'method' => 'bank', 'amount_cents' => 21650, 'fee_cents' => 0,
            'stripe_payment_method_id' => 'pm_test_bank', 'idempotency_key' => 'k1', 'status' => PaymentAttempt::STATUS_FAILED, 'failure_code' => 'insufficient_funds',
        ]);

        $lines = $this->mailLines(new PaymentStatusChangedNotification($payment), $customer);
        $text = implode("\n", $lines);

        $this->assertSame('Your payment did not go through', $lines[0]);
        $this->assertStringContainsString('Your $216.50 payment due Nov 15, 2026 did not go through.', $text);
        $this->assertStringContainsString('Reason: There was not enough money in the account.', $text);
        $this->assertStringContainsString('update your bank account or card on your Payments page', $text);
        // 10% of the payment is over the $5 minimum and under the $30 cap: $21.65, from the 10th day after the due date.
        $this->assertStringContainsString('on Nov 25, 2026, a late fee of $21.65 is added', $text);
        $this->assertSame('Open my payments', end($lines));
    }

    public function test_the_failed_payment_email_says_nothing_about_the_reason_when_it_is_not_known(): void
    {
        $customer = $this->customer();
        $payment = $this->payment($customer, ['status' => Payment::STATUS_FAILED]);

        $text = implode("\n", $this->mailLines(new PaymentStatusChangedNotification($payment), $customer));

        $this->assertStringContainsString('did not go through', $text);
        $this->assertStringNotContainsString('Reason:', $text);
    }

    public function test_a_failure_code_is_turned_into_a_sentence_and_an_unknown_one_into_nothing(): void
    {
        $this->assertSame('The bank account is closed.', PaymentFailureReason::describe('account_closed'));
        $this->assertSame('The card was declined.', PaymentFailureReason::describe('generic_decline'));
        $this->assertNull(PaymentFailureReason::describe('something_new'));
        $this->assertNull(PaymentFailureReason::describe(null));
    }

    public function test_staff_and_other_payment_updates_keep_their_own_wording(): void
    {
        $customer = $this->customer();
        $paid = $this->payment($customer, ['status' => Payment::STATUS_PAID, 'paid_date' => '2026-11-15']);
        $admin = User::factory()->create(['role' => User::ROLE_ADMIN]);
        $failed = $this->payment($this->customer(), ['status' => Payment::STATUS_FAILED]);

        $this->assertSame('Payment update', (new PaymentStatusChangedNotification($paid))->toArray($customer)['title']);
        $this->assertSame('Payment failed: Jane Doe', (new PaymentStatusChangedNotification($failed))->toArray($admin)['title']);
        $this->assertSame('Your payment did not go through', (new PaymentStatusChangedNotification($failed))->toArray($failed->leaseAgreement->customer)['title']);
    }

    public function test_the_day_after_a_missed_due_date_the_customer_is_told_once(): void
    {
        Notification::fake();
        $customer = $this->customer();
        $payment = $this->payment($customer);

        $this->artisan('payments:send-overdue-notices')->assertSuccessful();
        $this->assertNotNull($payment->fresh()->overdue_notice_sent_at, 'The payment is marked as told.');

        // Running the job again the same day sends nothing more for a payment that was already told.
        $this->artisan('payments:send-overdue-notices')->assertSuccessful();
        Notification::assertSentToTimes($customer, PaymentOverdueNotification::class, 1);

        $lines = $this->mailLines(new PaymentOverdueNotification($payment), $customer);
        $text = implode('
', $lines);
        $this->assertSame('Your payment has not been received', $lines[0]);
        $this->assertStringContainsString('We have not received your $216.50 payment that was due Nov 15, 2026.', $text);
        $this->assertStringContainsString('If you have already paid, thank you', $text);
        $this->assertStringContainsString('on Nov 25, 2026, a late fee of $21.65 is added', $text);
    }

    public function test_the_overdue_email_is_sent_to_the_customer_on_the_first_run(): void
    {
        Notification::fake();
        $customer = $this->customer();
        $this->payment($customer);

        $this->artisan('payments:send-overdue-notices')->assertSuccessful();

        Notification::assertSentTo($customer, PaymentOverdueNotification::class);
    }

    public function test_no_overdue_email_for_a_payment_that_is_not_overdue_or_not_the_customers_to_miss(): void
    {
        Notification::fake();

        $beforePickup = $this->customer();
        $this->payment($beforePickup, [], pickedUp: false);
        $notDueYet = $this->customer();
        $this->payment($notDueYet, ['due_date' => '2026-11-16']);
        $tooOld = $this->customer();
        $this->payment($tooOld, ['due_date' => '2026-11-10']);
        $alreadyFailed = $this->customer();
        $this->payment($alreadyFailed, ['status' => Payment::STATUS_FAILED]);
        $paid = $this->customer();
        $this->payment($paid, ['status' => Payment::STATUS_PAID, 'paid_date' => '2026-11-15']);
        $onItsWay = $this->customer();
        $this->payment($onItsWay, ['stripe_payment_intent_id' => 'pi_test_in_flight']);
        $notRent = $this->customer();
        $this->payment($notRent, ['type' => Payment::TYPE_LATE_FEE]);

        $this->artisan('payments:send-overdue-notices')->assertSuccessful();

        Notification::assertNothingSent();
    }

    public function test_both_emails_are_sent_even_to_a_customer_who_switched_off_payment_reminder_emails(): void
    {
        Notification::fake();
        // Crucial communication to keep the lease in good standing (client, Joel, 2026-11-09): the opt-out does not apply to these two.
        $optedOut = $this->customer();
        $optedOut->customerProfile()->create(['payment_reminder_emails' => false]);
        $this->payment($optedOut);

        $this->artisan('payments:send-overdue-notices')->assertSuccessful();
        Notification::assertSentTo($optedOut, PaymentOverdueNotification::class);

        $failed = $this->payment($optedOut, ['due_date' => '2026-11-14']);
        LeaseEngine::applyPaymentStatusChange($failed, Payment::STATUS_FAILED, 'ach', null);
        Notification::assertSentTo($optedOut, PaymentStatusChangedNotification::class, fn ($n) => $n->toArray($optedOut)['title'] === 'Your payment did not go through');
    }

    public function test_a_payment_that_was_received_still_respects_the_reminder_opt_out(): void
    {
        Notification::fake();
        $optedOut = $this->customer();
        $optedOut->customerProfile()->create(['payment_reminder_emails' => false]);
        $payment = $this->payment($optedOut);

        LeaseEngine::applyPaymentStatusChange($payment, Payment::STATUS_PAID, 'ach', null);

        Notification::assertNotSentTo($optedOut, PaymentStatusChangedNotification::class);
    }
}
