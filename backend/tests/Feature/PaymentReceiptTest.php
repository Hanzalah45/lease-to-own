<?php

namespace Tests\Feature;

use App\Models\AdminPermission;
use App\Models\EmailLog;
use App\Models\LeaseAgreement;
use App\Models\Payment;
use App\Models\PaymentAuditLog;
use App\Models\PaymentReceipt;
use App\Models\PaymentReceiptSend;
use App\Models\PaymentRefund;
use App\Models\User;
use App\Notifications\PaymentPartReceivedNotification;
use App\Notifications\PaymentReceiptNotification;
use App\Notifications\PaymentRefundedNotification;
use App\Notifications\PaymentStatusChangedNotification;
use App\Services\LeaseEngine;
use App\Services\PaymentReceipts;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Mail\Events\MessageSending;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\Mailer\SentMessage;
use Symfony\Component\Mime\Email;
use Tests\TestCase;

/**
 * Formal receipts (client, Joel, 2026-10-19 checklist, section 8): a numbered receipt for every payment (or piece of one) and refund,
 * emailed with a PDF copy, kept as the audit copy, sendable again by staff, with a log of every send.
 */
class PaymentReceiptTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(Carbon::parse('2026-10-20 15:00:00', 'UTC'));
        $this->admin = User::factory()->create(['role' => User::ROLE_SUPER_ADMIN, 'name' => 'Stefany Staff']);
    }

    /** Rent $200 at 8.25% tax: a monthly payment is $216.50, of which $16.50 is sales tax. */
    private function lease(array $overrides = [], string $email = 'jane@example.test'): LeaseAgreement
    {
        $customer = User::factory()->create(['role' => User::ROLE_CUSTOMER, 'name' => 'Jane Doe', 'email' => $email]);
        $customer->customerProfile()->create(['stripe_customer_id' => 'cus_receipt']);

        return LeaseAgreement::factory()->pickedUp()->create(array_merge([
            'customer_id' => $customer->id, 'monthly_rental_payment' => 200, 'sales_tax_rate' => 0.0825, 'ldw_selected' => false, 'ldw_amount' => 0,
            'additional_funds' => 0, 'autopay_enabled' => false,
        ], $overrides));
    }

    private function due(LeaseAgreement $lease, array $overrides = []): Payment
    {
        return Payment::factory()->create(array_merge([
            'lease_agreement_id' => $lease->id, 'type' => Payment::TYPE_RENTAL, 'amount' => 216.50, 'due_date' => '2026-10-10', 'status' => Payment::STATUS_PENDING,
        ], $overrides));
    }

    private function pay(Payment $payment, string $method = 'cash'): Payment
    {
        return LeaseEngine::applyPaymentStatusChange($payment, Payment::STATUS_PAID, $method, $this->admin->id);
    }

    /** @return list<Email> */
    private function sentTo(string $address): array
    {
        return Mail::mailer('array')->getSymfonyTransport()->messages()
            ->map(fn (SentMessage $m) => $m->getOriginalMessage())
            ->filter(fn ($email) => collect($email->getTo())->contains(fn ($a) => $a->getAddress() === $address))
            ->values()
            ->all();
    }

    private function asStaff(): self
    {
        return $this->actingAs($this->admin, 'sanctum');
    }

    // ---- writing the receipt ----

    public function test_a_payment_that_lands_gets_a_numbered_receipt_with_its_breakdown_and_a_stored_copy(): void
    {
        $lease = $this->lease();
        $payment = $this->due($lease, ['card_fee_amount' => 6.50]);

        $this->pay($payment, 'card');

        $receipt = PaymentReceipt::firstOrFail();
        $this->assertSame('R-000001', $receipt->receipt_number);
        $this->assertSame("payment:{$payment->id}", $receipt->source_key);
        $this->assertSame('payment', $receipt->kind);
        $this->assertSame($lease->id, $receipt->lease_agreement_id);
        $this->assertSame('223.00', $receipt->amount);
        $d = $receipt->details;
        $this->assertSame('Monthly payment due October 10, 2026', $d['description']);
        $this->assertSame('Card', $d['method_label']);
        $this->assertSame('Jane Doe', $d['customer']['name']);
        $this->assertEquals(
            [['Rent', 200.0], ['Sales tax', 16.5], ['Card fee', 6.5]],
            array_map(fn ($l) => [$l['label'], $l['amount']], $d['lines']),
        );
        $this->assertEqualsWithDelta(223.0, array_sum(array_column($d['lines'], 'amount')), 0.001);
        $this->assertTrue($d['balance']['paid_in_full']);
        $this->assertEqualsWithDelta(0.0, $d['balance']['remaining'], 0.001);

        // The stored copy is a real PDF on the private disk, with a fingerprint that proves it was not changed.
        $stored = $receipt->pdf_path;
        Storage::disk('local')->assertExists($stored);
        $this->assertSame('receipts/2026/R-000001.pdf', $stored);
        $bytes = Storage::disk('local')->get($stored);
        $this->assertStringStartsWith('%PDF', $bytes);
        $this->assertSame(hash('sha256', $bytes), $receipt->pdf_sha256);
        // Where the copy is kept is nobody's business but the portal's.
        $this->assertArrayNotHasKey('pdf_path', $receipt->toArray());
        $this->assertTrue($receipt->toArray()['has_copy']);
    }

    public function test_every_kind_of_charge_is_split_into_lines_that_add_up(): void
    {
        $lease = $this->lease();
        $cases = [
            [Payment::TYPE_DEPOSIT, 500.0, ['Security deposit']],
            [Payment::TYPE_LATE_FEE, 25.0, ['Late fee']],
            [Payment::TYPE_EXTRA_PAYMENT, 100.0, ['Extra payment toward the early purchase price']],
            [Payment::TYPE_PICKUP_BALANCE, 366.5, ['First month rent', 'Tracking device fee', 'Sales tax']],
        ];

        foreach ($cases as [$type, $amount, $labels]) {
            $payment = $this->due($lease, ['type' => $type, 'amount' => $amount]);
            [$receipt] = PaymentReceipts::make()->issueForPayment($payment->fresh()->forceFill(['status' => Payment::STATUS_PAID, 'paid_date' => '2026-10-20', 'method' => 'cash']));

            $this->assertSame($labels, array_column($receipt->details['lines'], 'label'), $type);
            $this->assertEqualsWithDelta($amount, array_sum(array_column($receipt->details['lines'], 'amount')), 0.001, $type);
            $this->assertEqualsWithDelta($amount, (float) $receipt->amount, 0.001, $type);
        }
        // Numbers only ever go up, one per receipt.
        $this->assertSame(['R-000001', 'R-000002', 'R-000003', 'R-000004'], PaymentReceipt::orderBy('id')->pluck('receipt_number')->all());
    }

    public function test_the_same_money_never_gets_two_receipts_or_two_emails(): void
    {
        Notification::fake();
        $payment = $this->due($this->lease());

        $paid = $this->pay($payment);
        PaymentReceipts::onPaid($paid->fresh());
        PaymentReceipts::onPaid($paid->fresh());

        $this->assertSame(1, PaymentReceipt::count());
        $this->assertSame(1, PaymentReceiptSend::count());
        Notification::assertSentOnDemandTimes(PaymentReceiptNotification::class, 1);
    }

    public function test_the_first_month_recorded_alongside_the_tracking_fee_moved_no_money_and_gets_no_receipt(): void
    {
        $lease = $this->lease();
        $this->due($lease, ['type' => Payment::TYPE_PICKUP_BALANCE, 'amount' => 366.5, 'status' => Payment::STATUS_PAID, 'paid_date' => '2026-10-01', 'method' => 'card', 'stripe_payment_intent_id' => 'pi_pickup']);
        $mirror = $this->due($lease, ['due_date' => '2026-10-01', 'status' => Payment::STATUS_PAID, 'paid_date' => '2026-10-01']);

        $this->assertFalse(PaymentReceipts::onPaid($mirror->fresh()));

        $this->assertSame(0, PaymentReceipt::count());
    }

    // ---- emailing it ----

    public function test_the_customer_is_emailed_the_receipt_with_the_pdf_and_gets_no_second_payment_email(): void
    {
        $lease = $this->lease();
        $payment = $this->due($lease);

        $this->pay($payment);

        $mails = $this->sentTo('jane@example.test');
        $this->assertCount(1, $mails, 'The receipt is the customer\'s one email for this payment.');
        $this->assertSame('Your receipt R-000001 from Prostart Leasing', $mails[0]->getSubject());
        $attachments = $mails[0]->getAttachments();
        $this->assertCount(1, $attachments);
        $this->assertSame('Receipt-R-000001.pdf', $attachments[0]->getFilename());
        $this->assertSame('application/pdf', $attachments[0]->getMediaType().'/'.$attachments[0]->getMediaSubtype());
        $this->assertStringStartsWith('%PDF', $attachments[0]->getBody());
        $this->assertStringContainsString('216.50', (string) $mails[0]->getHtmlBody());

        // The in-app notice is still there.
        $this->assertSame(1, $lease->customer->notifications()->count());
        // The mail log has it with its own name.
        $log = EmailLog::where('to_address', 'jane@example.test')->firstOrFail();
        $this->assertSame('Payment receipt', $log->kind_label);
        $this->assertSame(EmailLog::STATUS_SENT, $log->status);

        $send = PaymentReceiptSend::firstOrFail();
        $this->assertSame('jane@example.test', $send->email);
        $this->assertSame('auto', $send->trigger);
        $this->assertSame('sent', $send->outcome);
        $this->assertNull($send->sent_by);
        $this->assertNotNull($send->sent_at);
    }

    public function test_a_customer_who_does_not_take_payment_emails_has_the_receipt_on_file_but_is_not_emailed(): void
    {
        $lease = $this->lease();
        $lease->customer->customerProfile->update(['payment_reminder_emails' => false]);

        $this->pay($this->due($lease));

        $this->assertSame(1, PaymentReceipt::count());
        $this->assertSame(0, PaymentReceiptSend::count());
        $this->assertSame([], $this->sentTo('jane@example.test'));
        $this->assertSame(0, $lease->customer->notifications()->count());
    }

    public function test_a_payment_that_failed_still_gets_the_ordinary_notice_and_no_receipt(): void
    {
        Notification::fake();
        $lease = $this->lease();

        LeaseEngine::applyPaymentStatusChange($this->due($lease), Payment::STATUS_FAILED, 'ach', null);

        $this->assertSame(0, PaymentReceipt::count());
        Notification::assertSentTo($lease->customer, PaymentStatusChangedNotification::class, fn ($n, $channels) => in_array('mail', $channels, true));
    }

    public function test_a_receipt_the_mail_service_refuses_is_logged_as_failed_and_the_ordinary_notice_is_sent_instead(): void
    {
        // The mail service takes every email except the receipt.
        Event::listen(MessageSending::class, function (MessageSending $event) {
            if (str_contains((string) $event->message->getSubject(), 'receipt')) {
                throw new \RuntimeException('Mail service down');
            }
        });
        $lease = $this->lease();

        $this->pay($this->due($lease));

        // The receipt itself is never lost, and the refusal is written down.
        $this->assertSame(1, PaymentReceipt::count());
        $send = PaymentReceiptSend::firstOrFail();
        $this->assertSame('failed', $send->outcome);
        $this->assertSame('Mail service down', $send->error);
        $this->assertFalse(PaymentReceipts::make()->wasAutoMailed(PaymentReceipt::firstOrFail()));
        // So the customer still hears about the payment, in the ordinary email.
        $mails = $this->sentTo('jane@example.test');
        $this->assertCount(1, $mails);
        $this->assertSame('Payment update', $mails[0]->getSubject());
    }

    // ---- paid in parts ----

    public function test_each_piece_of_a_split_payment_gets_its_own_receipt_and_the_last_one_says_paid_in_full(): void
    {
        Notification::fake();
        $lease = $this->lease(['stripe_bank_payment_method_id' => 'pm_bank']);
        $payment = $this->due($lease);
        $customer = $lease->customer;

        $this->asStaff()->postJson("/api/admin/payments/{$payment->id}/parts", ['method' => 'cash', 'amount' => 100])->assertCreated();

        $first = PaymentReceipt::firstOrFail();
        $this->assertSame('part', explode(':', $first->source_key)[0]);
        $this->assertSame('100.00', $first->amount);
        $this->assertFalse($first->details['balance']['paid_in_full']);
        $this->assertEqualsWithDelta(116.5, $first->details['balance']['remaining'], 0.001);
        $this->assertEqualsWithDelta(100.0, $first->details['balance']['paid_to_date'], 0.001);
        $this->assertEqualsWithDelta(100.0, array_sum(array_column($first->details['lines'], 'amount')), 0.001);
        // The receipt is what the customer is emailed: the portal notice stays, the second email is left out.
        Notification::assertSentTo($customer, PaymentPartReceivedNotification::class, fn ($n, $channels) => $channels === ['database']);
        Notification::assertSentOnDemandTimes(PaymentReceiptNotification::class, 1);

        $this->asStaff()->postJson("/api/admin/payments/{$payment->id}/parts", ['method' => 'cash', 'amount' => 116.5])->assertCreated();

        $this->assertSame(2, PaymentReceipt::count());
        $last = PaymentReceipt::orderByDesc('id')->firstOrFail();
        $this->assertSame('116.50', $last->amount);
        $this->assertTrue($last->details['balance']['paid_in_full']);
        $this->assertEqualsWithDelta(0.0, $last->details['balance']['remaining'], 0.001);
        $this->assertSame(Payment::STATUS_PAID, $payment->fresh()->status);
        // One receipt email per piece, and the payment finishing adds no email of its own.
        Notification::assertSentOnDemandTimes(PaymentReceiptNotification::class, 2);
        Notification::assertSentTo($customer, PaymentStatusChangedNotification::class, fn ($n, $channels) => $channels === ['database']);
        $this->assertSame(2, PaymentReceiptSend::where('trigger', 'auto')->count());
    }

    // ---- refunds ----

    public function test_a_refund_gets_a_receipt_that_replaces_the_refund_email(): void
    {
        Notification::fake();
        $lease = $this->lease();
        $payment = $this->due($lease, ['status' => Payment::STATUS_PAID, 'paid_date' => '2026-10-15', 'method' => 'cash']);

        $this->asStaff()->postJson("/api/admin/payments/{$payment->id}/refunds", ['amount' => 100, 'reason' => 'customer_request', 'note' => 'Returned a part'])->assertCreated();

        $receipt = PaymentReceipt::firstOrFail();
        $this->assertSame('refund', $receipt->kind);
        $this->assertSame('100.00', $receipt->amount);
        $this->assertSame('R-000001', $receipt->receipt_number);
        $this->assertEqualsWithDelta(100.0, array_sum(array_column($receipt->details['lines'], 'amount')), 0.001);
        $this->assertEqualsWithDelta(100.0, $receipt->details['balance']['refunded_to_date'], 0.001);
        $this->assertEqualsWithDelta(216.5, $receipt->details['balance']['price'], 0.001);
        // The reason staff chose is internal, and the receipt is the customer's.
        $this->assertStringNotContainsString('customer_request', json_encode($receipt->details));
        $this->assertStringNotContainsString('Returned a part', json_encode($receipt->details));
        Notification::assertSentOnDemand(PaymentReceiptNotification::class, function ($n, $channels, $notifiable) {
            $mail = $n->toMail($notifiable);

            return $notifiable->routes['mail'] === 'jane@example.test'
                && $mail->subject === 'Your refund receipt R-000001 from Prostart Leasing'
                && count($mail->rawAttachments) === 1;
        });
        Notification::assertSentTo($lease->customer, PaymentRefundedNotification::class, fn ($n, $channels) => $channels === ['database']);
        Notification::assertSentTo($this->admin, PaymentRefundedNotification::class);

        // A second refund of the same payment is a second receipt, and says how much has gone back in all.
        $this->asStaff()->postJson("/api/admin/payments/{$payment->id}/refunds", ['amount' => 50, 'reason' => 'overpayment'])->assertCreated();
        $second = PaymentReceipt::orderByDesc('id')->firstOrFail();
        $this->assertSame('R-000002', $second->receipt_number);
        $this->assertEqualsWithDelta(150.0, $second->details['balance']['refunded_to_date'], 0.001);
    }

    public function test_a_lost_chargeback_gets_no_receipt(): void
    {
        $payment = $this->due($this->lease(), ['status' => Payment::STATUS_PAID, 'paid_date' => '2026-10-15', 'method' => 'card']);
        $refund = PaymentRefund::create([
            'payment_id' => $payment->id, 'amount' => 216.5, 'reason' => PaymentRefund::REASON_CHARGEBACK, 'source' => PaymentRefund::SOURCE_DISPUTE,
            'status' => PaymentRefund::STATUS_SUCCEEDED, 'refunded_at' => now(),
        ]);

        $this->assertFalse(PaymentReceipts::onRefund($refund));

        $this->assertSame(0, PaymentReceipt::count());
    }

    // ---- staff: the log, sending again, the copy ----

    public function test_staff_send_a_receipt_again_and_every_send_is_logged(): void
    {
        $lease = $this->lease();
        $this->pay($this->due($lease));
        $receipt = PaymentReceipt::firstOrFail();

        $this->asStaff()->postJson("/api/admin/payment-receipts/{$receipt->id}/send")->assertOk()->assertJsonPath('mailed', true);
        $response = $this->asStaff()->postJson("/api/admin/payment-receipts/{$receipt->id}/send", ['email' => 'accountant@example.test'])->assertOk();

        $sends = $response->json('data.sends');
        $this->assertSame(['auto', 'manual', 'manual'], array_column($sends, 'trigger'));
        $this->assertSame(['jane@example.test', 'jane@example.test', 'accountant@example.test'], array_column($sends, 'email'));
        $this->assertSame([null, 'Stefany Staff', 'Stefany Staff'], array_map(fn ($s) => $s['sent_by']['name'] ?? null, $sends));
        $this->assertCount(1, $this->sentTo('accountant@example.test'));
        // The same stored copy goes out each time, never a fresh one.
        $this->assertSame(
            $this->sentTo('jane@example.test')[0]->getAttachments()[0]->getBody(),
            $this->sentTo('accountant@example.test')[0]->getAttachments()[0]->getBody(),
        );
        $this->assertSame(2, PaymentAuditLog::where('action', 'receipt.resent')->count());
        $this->asStaff()->postJson("/api/admin/payment-receipts/{$receipt->id}/send", ['email' => 'nope'])->assertStatus(422)->assertJsonValidationErrors('email');
    }

    public function test_a_receipt_can_always_be_sent_again_even_when_the_customer_does_not_take_payment_emails(): void
    {
        $lease = $this->lease();
        $lease->customer->customerProfile->update(['payment_reminder_emails' => false]);
        $this->pay($this->due($lease));

        $this->asStaff()->postJson('/api/admin/payment-receipts/'.PaymentReceipt::firstOrFail()->id.'/send')->assertOk()->assertJsonPath('mailed', true);

        $this->assertCount(1, $this->sentTo('jane@example.test'));
    }

    public function test_the_receipts_of_a_lease_are_listed_newest_first_with_their_log_and_the_copy_downloads(): void
    {
        $lease = $this->lease();
        $this->pay($this->due($lease));
        $this->pay($this->due($lease, ['due_date' => '2026-11-10']));
        $newest = PaymentReceipt::orderByDesc('id')->firstOrFail();

        $list = $this->asStaff()->getJson("/api/admin/lease-agreements/{$lease->id}/receipts")->assertOk();

        $this->assertSame(['R-000002', 'R-000001'], array_column($list->json('data'), 'receipt_number'));
        $this->assertSame('auto', $list->json('data.0.sends.0.trigger'));
        $this->assertSame('Monthly payment due November 10, 2026', $list->json('data.0.details.description'));
        $this->assertArrayNotHasKey('pdf_path', $list->json('data.0'));

        $download = $this->asStaff()->get("/api/admin/payment-receipts/{$newest->id}/download")->assertOk();
        $this->assertSame('application/pdf', $download->headers->get('Content-Type'));
        $this->assertStringContainsString('Receipt-R-000002.pdf', $download->headers->get('Content-Disposition'));
        $this->assertSame(Storage::disk('local')->get($newest->pdf_path), $download->getContent());
    }

    public function test_a_copy_that_went_missing_is_written_again_from_what_the_receipt_says(): void
    {
        $this->pay($this->due($this->lease()));
        $receipt = PaymentReceipt::firstOrFail();
        Storage::disk('local')->delete($receipt->pdf_path);

        $download = $this->asStaff()->get("/api/admin/payment-receipts/{$receipt->id}/download")->assertOk();

        $this->assertStringStartsWith('%PDF', $download->getContent());
        Storage::disk('local')->assertExists($receipt->fresh()->pdf_path);
    }

    public function test_only_staff_with_payment_tracking_can_use_the_staff_receipt_endpoints(): void
    {
        $lease = $this->lease();
        $this->pay($this->due($lease));
        $receipt = PaymentReceipt::firstOrFail();
        $reviewer = User::factory()->create(['role' => User::ROLE_ADMIN]);
        AdminPermission::create(['user_id' => $reviewer->id, 'permission' => AdminPermission::APPLICATION_REVIEW]);

        foreach ([$lease->customer, $reviewer] as $user) {
            $this->actingAs($user, 'sanctum')->getJson("/api/admin/lease-agreements/{$lease->id}/receipts")->assertForbidden();
            $this->actingAs($user, 'sanctum')->get("/api/admin/payment-receipts/{$receipt->id}/download")->assertForbidden();
            $this->actingAs($user, 'sanctum')->postJson("/api/admin/payment-receipts/{$receipt->id}/send")->assertForbidden();
        }
    }

    // ---- the customer's own ----

    public function test_a_customer_sees_and_downloads_only_their_own_receipts(): void
    {
        $mine = $this->lease();
        $theirs = $this->lease([], 'other@example.test');
        $this->pay($this->due($mine));
        $this->pay($this->due($theirs));
        $own = PaymentReceipt::where('lease_agreement_id', $mine->id)->firstOrFail();
        $other = PaymentReceipt::where('lease_agreement_id', $theirs->id)->firstOrFail();

        $list = $this->actingAs($mine->customer, 'sanctum')->getJson('/api/customer/receipts')->assertOk();

        $this->assertSame([$own->id], array_column($list->json('data'), 'id'));
        $this->assertSame('Monthly payment due October 10, 2026', $list->json('data.0.description'));
        $this->assertArrayNotHasKey('details', $list->json('data.0'));
        $this->actingAs($mine->customer, 'sanctum')->get("/api/customer/receipts/{$own->id}/download")->assertOk()->assertHeader('Content-Type', 'application/pdf');
        $this->actingAs($mine->customer, 'sanctum')->get("/api/customer/receipts/{$other->id}/download")->assertNotFound();
        $this->actingAs($this->admin, 'sanctum')->getJson('/api/customer/receipts')->assertForbidden();
    }
}
