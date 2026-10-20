<?php

namespace Tests\Feature;

use App\Models\AdminPermission;
use App\Models\EmailLog;
use App\Models\LeaseAgreement;
use App\Models\Payment;
use App\Models\PaymentReceipt;
use App\Models\User;
use App\Notifications\EmailBouncedNotification;
use App\Notifications\VerifyEmailNotification;
use App\Services\LeaseEngine;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Testing\TestResponse;
use Symfony\Component\Mailer\SentMessage;
use Symfony\Component\Mailer\Transport\AbstractTransport;
use Tests\TestCase;

/**
 * Delivered or bounced (client, Joel, 2026-10-15 and the 2026-10-19 checklist). Amazon SES reports what happened to each email through
 * Amazon SNS, and the email log, the receipts and the payment links show it. SNS messages are signed with a certificate; the tests sign
 * theirs with one made for the test and serve it where the message says it lives.
 */
class SesDeliveryStatusTest extends TestCase
{
    use RefreshDatabase;

    private const TOPIC = 'arn:aws:sns:us-east-2:123456789012:prostart-ses-events';

    private const CERT_URL = 'https://sns.us-east-2.amazonaws.com/SimpleNotificationService-test.pem';

    /** @var \OpenSSLAsymmetricKey */
    private $key;

    private string $certificate;

    protected function setUp(): void
    {
        parent::setUp();
        config(['services.ses_events.topic_arn' => self::TOPIC]);

        $conf = sys_get_temp_dir().'/sns-test-openssl.cnf';
        file_put_contents($conf, "[req]\ndistinguished_name = dn\nprompt = no\n[dn]\nCN = sns.test\n");
        $options = ['config' => $conf, 'private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA];
        $this->key = openssl_pkey_new($options);
        $csr = openssl_csr_new(['commonName' => 'sns.test'], $this->key, $options);
        openssl_x509_export(openssl_csr_sign($csr, null, $this->key, 1, $options), $pem);
        $this->certificate = $pem;

        Http::fake([self::CERT_URL => Http::response($this->certificate), '*' => Http::response('ok')]);
    }

    // ---- helpers ----

    /** A message from SNS, signed the way SNS signs it. */
    private function sns(string $type, array $fields, ?string $signWith = null): array
    {
        $message = $fields + [
            'Type' => $type,
            'MessageId' => 'sns-message-1',
            'TopicArn' => self::TOPIC,
            'Timestamp' => '2026-10-21T12:00:00.000Z',
            'SignatureVersion' => '2',
            'SigningCertURL' => self::CERT_URL,
        ];
        $signed = $type === 'Notification'
            ? ['Message', 'MessageId', 'Subject', 'Timestamp', 'TopicArn', 'Type']
            : ['Message', 'MessageId', 'SubscribeURL', 'Timestamp', 'Token', 'TopicArn', 'Type'];
        $text = '';
        foreach ($signed as $field) {
            if ($field === 'Subject' && ! isset($message['Subject'])) {
                continue;
            }
            $text .= $field."\n".$message[$field]."\n";
        }
        openssl_sign($text, $signature, $this->key, OPENSSL_ALGO_SHA256);
        $message['Signature'] = $signWith ?? base64_encode($signature);

        return $message;
    }

    /** What SES publishes to the topic for one event. */
    private function event(array $event): array
    {
        return $this->sns('Notification', ['Message' => json_encode($event)]);
    }

    private function snsPost(array $message): TestResponse
    {
        // SNS sends its JSON as text/plain.
        return $this->call('POST', '/api/webhooks/ses', [], [], [], ['CONTENT_TYPE' => 'text/plain; charset=UTF-8'], json_encode($message));
    }

    private function logRow(array $overrides = []): EmailLog
    {
        return EmailLog::create($overrides + [
            'to_address' => 'jane@example.test', 'subject' => 'Your receipt R-000001', 'kind' => 'PaymentReceiptNotification',
            'status' => 'sent', 'ses_message_id' => 'ses-abc-1',
        ]);
    }

    private function sesEvent(string $type, array $extra = [], string $messageId = 'ses-abc-1'): array
    {
        return ['eventType' => $type, 'mail' => ['messageId' => $messageId, 'destination' => ['jane@example.test']]] + $extra;
    }

    private function delivery(array $recipients = ['jane@example.test'], string $at = '2026-10-21T12:00:05.000Z'): array
    {
        return $this->sesEvent('Delivery', ['delivery' => ['timestamp' => $at, 'recipients' => $recipients]]);
    }

    private function bounce(string $type = 'Permanent', string $sub = 'General', string $address = 'jane@example.test'): array
    {
        return $this->sesEvent('Bounce', ['bounce' => [
            'bounceType' => $type, 'bounceSubType' => $sub, 'timestamp' => '2026-10-21T12:00:07.000Z',
            'bouncedRecipients' => [['emailAddress' => $address, 'diagnosticCode' => 'smtp; 550 5.1.1 user unknown']],
        ]]);
    }

    // ---- the email log ----

    public function test_a_delivery_report_marks_the_email_delivered_with_the_time(): void
    {
        $row = $this->logRow();

        $this->snsPost($this->event($this->delivery()))->assertOk();

        $row->refresh();
        $this->assertSame('delivered', $row->delivery_status);
        $this->assertSame('2026-10-21 12:00:05', $row->delivered_at->utc()->format('Y-m-d H:i:s'));
        $this->assertNotNull($row->delivery_updated_at);
        // The email service's own id stays behind the scenes.
        $this->assertArrayNotHasKey('ses_message_id', $row->toArray());
    }

    public function test_a_bounce_is_recorded_with_why_and_staff_are_told_when_it_is_for_good(): void
    {
        $admin = User::factory()->create(['role' => User::ROLE_SUPER_ADMIN]);
        $customer = User::factory()->create(['role' => User::ROLE_CUSTOMER, 'name' => 'Jane Doe', 'email' => 'jane@example.test']);
        $row = $this->logRow(['user_id' => $customer->id]);

        $this->snsPost($this->event($this->bounce()))->assertOk();

        $row->refresh();
        $this->assertSame('bounced', $row->delivery_status);
        $this->assertSame('Permanent General: smtp; 550 5.1.1 user unknown', $row->delivery_detail);
        $note = $admin->notifications()->firstOrFail();
        $this->assertSame(EmailBouncedNotification::class, $note->type);
        $this->assertSame('An email did not reach its person', $note->data['title']);
        $this->assertStringContainsString('jane@example.test (Jane Doe) bounced', $note->data['body']);
        $this->assertSame("/admin/customers/{$customer->id}", $note->data['action_url']);
    }

    public function test_a_bounce_that_may_still_work_out_is_recorded_but_does_not_alarm_staff(): void
    {
        $admin = User::factory()->create(['role' => User::ROLE_SUPER_ADMIN]);
        $row = $this->logRow();

        $this->snsPost($this->event($this->bounce('Transient', 'MailboxFull')))->assertOk();

        $this->assertSame('bounced', $row->fresh()->delivery_status);
        $this->assertSame(0, $admin->notifications()->count());
    }

    public function test_a_spam_complaint_is_recorded_and_staff_are_told(): void
    {
        $admin = User::factory()->create(['role' => User::ROLE_SUPER_ADMIN]);
        $row = $this->logRow();

        $this->snsPost($this->event($this->sesEvent('Complaint', ['complaint' => [
            'timestamp' => '2026-10-21T13:00:00.000Z', 'complaintFeedbackType' => 'abuse',
            'complainedRecipients' => [['emailAddress' => 'jane@example.test']],
        ]])))->assertOk();

        $this->assertSame('complaint', $row->fresh()->delivery_status);
        $this->assertSame('Marked as spam (abuse)', $row->fresh()->delivery_detail);
        $this->assertSame('An email was marked as spam', $admin->notifications()->firstOrFail()->data['title']);
    }

    public function test_a_held_up_email_becomes_delivered_and_a_weaker_result_never_replaces_a_stronger_one(): void
    {
        $row = $this->logRow();
        $delayed = $this->sesEvent('DeliveryDelay', ['deliveryDelay' => ['timestamp' => '2026-10-21T12:01:00.000Z', 'delayType' => 'MailboxFull', 'delayedRecipients' => [['emailAddress' => 'jane@example.test']]]]);

        $this->snsPost($this->event($delayed))->assertOk();
        $this->assertSame('delayed', $row->fresh()->delivery_status);
        $this->assertSame('Held up: MailboxFull', $row->fresh()->delivery_detail);

        $this->snsPost($this->event($this->delivery()))->assertOk();
        $this->assertSame('delivered', $row->fresh()->delivery_status);

        // News of a delay that arrives late, or the same delivery twice, changes nothing.
        $this->snsPost($this->event($delayed))->assertOk();
        $this->snsPost($this->event($this->delivery()))->assertOk();
        $this->assertSame('delivered', $row->fresh()->delivery_status);

        // A bounce is final.
        $this->snsPost($this->event($this->bounce()))->assertOk();
        $this->assertSame('bounced', $row->fresh()->delivery_status);
        $this->snsPost($this->event($this->delivery()))->assertOk();
        $this->assertSame('bounced', $row->fresh()->delivery_status);
    }

    public function test_one_message_to_several_people_is_matched_by_address(): void
    {
        $jane = $this->logRow();
        $other = $this->logRow(['to_address' => 'other@example.test']);

        $this->snsPost($this->event($this->delivery(['jane@example.test'])))->assertOk();

        $this->assertSame('delivered', $jane->fresh()->delivery_status);
        $this->assertNull($other->fresh()->delivery_status);

        $this->snsPost($this->event($this->bounce('Permanent', 'General', 'OTHER@example.test')))->assertOk();
        $this->assertSame('bounced', $other->fresh()->delivery_status, 'Addresses are compared without regard to case.');
        $this->assertSame('delivered', $jane->fresh()->delivery_status);
    }

    public function test_events_for_mail_the_portal_does_not_know_and_events_that_are_not_results_change_nothing(): void
    {
        $row = $this->logRow();

        $this->snsPost($this->event($this->sesEvent('Delivery', ['delivery' => ['recipients' => ['jane@example.test']]], 'some-other-message')))->assertOk();
        $this->snsPost($this->event($this->sesEvent('Send')))->assertOk();
        $this->snsPost($this->event($this->sesEvent('Open')))->assertOk();
        $this->snsPost($this->sns('Notification', ['Message' => 'not json at all']))->assertOk();

        $this->assertNull($row->fresh()->delivery_status);
    }

    // ---- who is believed ----

    public function test_a_message_that_was_changed_after_it_was_signed_is_refused(): void
    {
        $row = $this->logRow();
        $message = $this->event($this->bounce());
        $message['Message'] = json_encode($this->delivery());

        $this->snsPost($message)->assertStatus(400);

        $this->assertNull($row->fresh()->delivery_status);
    }

    public function test_a_message_with_a_bad_or_missing_signature_is_refused(): void
    {
        $row = $this->logRow();

        $this->snsPost($this->sns('Notification', ['Message' => json_encode($this->delivery())], base64_encode('forged')))->assertStatus(400);
        $unsigned = $this->event($this->delivery());
        unset($unsigned['Signature']);
        $this->snsPost($unsigned)->assertStatus(400);
        $this->call('POST', '/api/webhooks/ses', [], [], [], ['CONTENT_TYPE' => 'text/plain'], 'not json')->assertStatus(400);

        $this->assertNull($row->fresh()->delivery_status);
    }

    public function test_a_certificate_that_is_not_amazons_is_never_fetched_or_trusted(): void
    {
        $row = $this->logRow();
        foreach (['https://attacker.example/cert.pem', 'http://sns.us-east-2.amazonaws.com/cert.pem', 'https://sns.us-east-2.amazonaws.com.attacker.example/cert.pem', 'https://user@sns.us-east-2.amazonaws.com/cert.pem'] as $url) {
            $message = $this->sns('Notification', ['Message' => json_encode($this->delivery()), 'SigningCertURL' => $url]);

            $this->snsPost($message)->assertStatus(400);
        }

        $this->assertNull($row->fresh()->delivery_status);
        Http::assertNothingSent();
    }

    public function test_a_validly_signed_message_from_another_topic_is_refused(): void
    {
        $row = $this->logRow();

        $this->snsPost($this->sns('Notification', ['Message' => json_encode($this->delivery()), 'TopicArn' => 'arn:aws:sns:us-east-2:999999999999:someone-elses-topic']))->assertStatus(403);

        $this->assertNull($row->fresh()->delivery_status);
    }

    public function test_it_does_nothing_until_a_topic_is_configured(): void
    {
        config(['services.ses_events.topic_arn' => null]);
        $row = $this->logRow();

        $this->snsPost($this->event($this->delivery()))->assertStatus(503);

        $this->assertNull($row->fresh()->delivery_status);
    }

    // ---- confirming the subscription ----

    public function test_the_subscription_request_is_confirmed_by_opening_the_address_amazon_names(): void
    {
        $url = 'https://sns.us-east-2.amazonaws.com/?Action=ConfirmSubscription&TopicArn='.self::TOPIC.'&Token=abc';

        $this->snsPost($this->sns('SubscriptionConfirmation', ['Message' => 'You have chosen to subscribe', 'SubscribeURL' => $url, 'Token' => 'abc']))->assertOk();

        Http::assertSent(fn ($request) => $request->url() === $url);
    }

    public function test_a_subscription_request_pointing_anywhere_else_is_not_opened(): void
    {
        $url = 'https://attacker.example/confirm?Token=abc';

        $this->snsPost($this->sns('SubscriptionConfirmation', ['Message' => 'subscribe', 'SubscribeURL' => $url, 'Token' => 'abc']))->assertOk();

        Http::assertNotSent(fn ($request) => $request->url() === $url);
    }

    // ---- the id SES gives a message is kept ----

    public function test_the_id_the_email_service_gives_a_message_is_kept_on_its_log_row(): void
    {
        $this->app->forgetInstance('mail.manager');
        Mail::clearResolvedInstance('mail.manager');
        // Built like the real SES transport: once SES accepts the message, the id is written on it as a header.
        Mail::extend('ses-like', fn () => new class extends AbstractTransport
        {
            protected function doSend(SentMessage $message): void
            {
                $message->getOriginalMessage()->getHeaders()->addHeader('X-SES-Message-ID', '0100019abc-def-000000');
            }

            public function __toString(): string
            {
                return 'ses-like://';
            }
        });
        config(['mail.mailers.ses-like' => ['transport' => 'ses-like'], 'mail.default' => 'ses-like']);
        $customer = User::factory()->create(['role' => User::ROLE_CUSTOMER, 'email' => 'jordan@example.com']);

        $customer->notify(new VerifyEmailNotification('https://example.test/verify'));

        $row = EmailLog::firstOrFail();
        $this->assertSame('sent', $row->status);
        $this->assertSame('0100019abc-def-000000', EmailLog::query()->value('ses_message_id'));
        $this->assertNotSame('0100019abc-def-000000', $row->provider_message_id, 'The portal\'s own message id is kept apart.');
    }

    // ---- what staff see ----

    public function test_the_email_log_can_be_filtered_by_what_happened(): void
    {
        $admin = User::factory()->create(['role' => User::ROLE_SUPER_ADMIN]);
        $bounced = $this->logRow(['to_address' => 'a@example.test', 'delivery_status' => 'bounced', 'delivery_detail' => 'Permanent General']);
        $this->logRow(['to_address' => 'b@example.test', 'delivery_status' => 'delivered']);
        $this->logRow(['to_address' => 'c@example.test']);

        $all = $this->actingAs($admin, 'sanctum')->getJson('/api/admin/email-logs')->assertOk();
        $only = $this->actingAs($admin, 'sanctum')->getJson('/api/admin/email-logs?delivery=bounced')->assertOk();

        $this->assertSame(3, $all->json('meta.total'));
        $this->assertSame([$bounced->id], array_column($only->json('data'), 'id'));
        $this->assertSame('Permanent General', $only->json('data.0.delivery_detail'));
        $this->actingAs($admin, 'sanctum')->getJson('/api/admin/email-logs?delivery=nonsense')->assertStatus(422);
    }

    public function test_a_receipt_send_shows_what_happened_to_its_email(): void
    {
        $admin = User::factory()->create(['role' => User::ROLE_SUPER_ADMIN]);
        $customer = User::factory()->create(['role' => User::ROLE_CUSTOMER, 'name' => 'Jane Doe', 'email' => 'jane@example.test']);
        $customer->customerProfile()->create(['stripe_customer_id' => 'cus_x']);
        $lease = LeaseAgreement::factory()->pickedUp()->create(['customer_id' => $customer->id, 'monthly_rental_payment' => 200, 'sales_tax_rate' => 0, 'ldw_selected' => false, 'ldw_amount' => 0]);
        $payment = Payment::factory()->create(['lease_agreement_id' => $lease->id, 'type' => Payment::TYPE_RENTAL, 'amount' => 200, 'due_date' => '2026-10-10', 'status' => Payment::STATUS_PENDING]);
        LeaseEngine::applyPaymentStatusChange($payment, Payment::STATUS_PAID, 'cash', $admin->id);

        $receipt = PaymentReceipt::firstOrFail();
        $send = $receipt->sends()->firstOrFail();
        $log = EmailLog::where('kind', 'PaymentReceiptNotification')->firstOrFail();
        $this->assertSame($log->id, $send->email_log_id, 'The send points at the email it made.');

        // SES accepted it, then reported it delivered.
        $log->update(['ses_message_id' => 'ses-receipt-1']);
        $this->snsPost($this->event($this->sesEvent('Delivery', ['delivery' => ['timestamp' => '2026-10-21T12:00:05.000Z', 'recipients' => ['jane@example.test']]], 'ses-receipt-1')))->assertOk();

        $response = $this->actingAs($admin, 'sanctum')->getJson("/api/admin/lease-agreements/{$lease->id}/receipts")->assertOk();

        $this->assertSame('delivered', $response->json('data.0.sends.0.email_log.delivery_status'));
        $this->assertNotNull($response->json('data.0.sends.0.email_log.delivered_at'));
        $this->assertArrayNotHasKey('ses_message_id', $response->json('data.0.sends.0.email_log'));
    }

    public function test_the_log_row_of_an_email_is_found_only_when_it_is_recent(): void
    {
        $recent = $this->logRow();
        $old = $this->logRow(['to_address' => 'old@example.test']);
        $old->forceFill(['created_at' => now()->subHour()])->save();

        $this->assertSame($recent->id, EmailLog::latestFor('JANE@example.test', 'PaymentReceiptNotification')?->id);
        $this->assertNull(EmailLog::latestFor('old@example.test', 'PaymentReceiptNotification'));
        $this->assertNull(EmailLog::latestFor('jane@example.test', 'PaymentLinkNotification'));
    }

    public function test_only_staff_who_may_see_the_email_log_can_filter_it(): void
    {
        $restricted = User::factory()->create(['role' => User::ROLE_ADMIN]);
        AdminPermission::create(['user_id' => $restricted->id, 'permission' => AdminPermission::PAYMENT_TRACKING]);

        $this->actingAs($restricted, 'sanctum')->getJson('/api/admin/email-logs?delivery=bounced')->assertForbidden();
    }
}
