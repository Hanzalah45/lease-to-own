<?php

namespace Tests\Feature;

use App\Models\AdminPermission;
use App\Models\EmailLog;
use App\Models\User;
use App\Notifications\RequestContractSignatureNotification;
use App\Services\EmailLogger;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Schema;
use Symfony\Component\Mailer\Exception\TransportException;
use Symfony\Component\Mailer\SentMessage;
use Symfony\Component\Mailer\Transport\AbstractTransport;
use Tests\TestCase;

/**
 * Real case (client, Joel, 2026-10-12): a customer's signing links went to a
 * mistyped address and nothing anywhere showed where they had gone. Every
 * email the system sends is now recorded, with what the email service answered.
 */
class EmailLogTest extends TestCase
{
    use RefreshDatabase;

    private function customer(array $overrides = []): User
    {
        return User::factory()->create(array_merge(['role' => User::ROLE_CUSTOMER, 'status' => 'pending'], $overrides));
    }

    private function admin(): User
    {
        return User::factory()->create(['role' => User::ROLE_SUPER_ADMIN]);
    }

    public function test_an_email_is_recorded_with_who_it_went_to_and_that_it_was_accepted(): void
    {
        $customer = $this->customer(['email' => 'jordan@example.com']);

        $customer->notify(new RequestContractSignatureNotification('https://example.test/sign?id=1&signature=private'));

        $log = EmailLog::firstOrFail();
        $this->assertSame($customer->id, $log->user_id);
        $this->assertSame('jordan@example.com', $log->to_address);
        $this->assertSame('RequestContractSignatureNotification', $log->kind);
        $this->assertSame('Contract signing link', $log->kind_label);
        $this->assertSame(EmailLog::STATUS_SENT, $log->status);
        $this->assertNotEmpty($log->subject);
        $this->assertNotEmpty($log->provider_message_id);
        $this->assertNull($log->error);
    }

    public function test_a_send_through_a_transport_that_copies_the_message_is_still_confirmed(): void
    {
        // The real email service (SES) goes through Symfony's AbstractTransport, which sends a COPY of the
        // message: the "sent" event then carries a different object than the "sending" one. The first release
        // matched by object and so showed every real email as failed (found live 2026-10-13).
        $this->useMailerThatAccepts();
        $customer = $this->customer(['email' => 'real@example.com']);

        $customer->notify(new RequestContractSignatureNotification('https://example.test/sign'));
        $this->app->terminate();

        $log = EmailLog::firstOrFail();
        $this->assertSame(EmailLog::STATUS_SENT, $log->status);
        $this->assertNull($log->error);
        $this->assertNotEmpty($log->provider_message_id);
    }

    public function test_several_emails_in_one_request_each_settle_to_their_own_row(): void
    {
        $this->useMailerThatAccepts();
        $a = $this->customer(['email' => 'a@example.com']);
        $b = $this->customer(['email' => 'b@example.com']);

        $a->notify(new RequestContractSignatureNotification('https://example.test/a'));
        $b->notify(new RequestContractSignatureNotification('https://example.test/b'));
        $a->notify(new RequestContractSignatureNotification('https://example.test/a2'));
        $this->app->terminate();

        $this->assertSame(
            [EmailLog::STATUS_SENT, EmailLog::STATUS_SENT, EmailLog::STATUS_SENT],
            EmailLog::orderBy('id')->pluck('status')->all(),
        );
        $this->assertSame(['a@example.com', 'b@example.com', 'a@example.com'], EmailLog::orderBy('id')->pluck('to_address')->all());
    }

    public function test_the_migration_corrects_only_the_rows_the_first_release_wrongly_marked_failed(): void
    {
        $wrong = EmailLog::create(['to_address' => 'a@example.com', 'status' => 'failed', 'error' => 'The email service did not confirm this message. See the server error log.']);
        $real = EmailLog::create(['to_address' => 'b@example.com', 'status' => 'failed', 'error' => 'Request to AWS SES API failed. Reason: Email address is not verified.']);
        $fine = EmailLog::create(['to_address' => 'c@example.com', 'status' => 'sent']);

        $migration = require database_path('migrations/2026_10_14_100000_correct_email_log_rows_wrongly_marked_failed.php');
        $migration->up();
        $migration->up(); // running it again changes nothing

        $this->assertSame(['sent', null], [$wrong->fresh()->status, $wrong->fresh()->error]);
        $this->assertSame('failed', $real->fresh()->status);
        $this->assertStringContainsString('not verified', $real->fresh()->error);
        $this->assertSame('sent', $fine->fresh()->status);
    }

    public function test_a_mistyped_address_is_logged_exactly_as_typed_and_still_linked_to_the_customer(): void
    {
        $customer = $this->customer(['email' => 'talley@gmail.comt']);

        $customer->notify(new RequestContractSignatureNotification('https://example.test/sign'));

        $log = EmailLog::firstOrFail();
        $this->assertSame('talley@gmail.comt', $log->to_address);
        $this->assertSame($customer->id, $log->user_id);
    }

    public function test_the_private_link_in_the_message_is_not_stored(): void
    {
        $customer = $this->customer();
        $customer->notify(new RequestContractSignatureNotification('https://example.test/sign?signature=secret-token-123'));

        $stored = json_encode(EmailLog::firstOrFail()->toArray());
        $this->assertStringNotContainsString('secret-token-123', $stored);
    }

    public function test_a_failed_send_is_recorded_with_the_error_from_the_email_service(): void
    {
        $this->useMailerThatThrows('Request to AWS SES API failed. Reason: Email address is not verified.');
        $customer = $this->customer(['email' => 'blocked@example.com']);

        try {
            $customer->notify(new RequestContractSignatureNotification('https://example.test/sign'));
            $this->fail('The send should have thrown.');
        } catch (TransportException $e) {
            report($e); // what the error handler does for a failed send
        }

        $log = EmailLog::firstOrFail();
        $this->assertSame(EmailLog::STATUS_FAILED, $log->status);
        $this->assertStringContainsString('Email address is not verified', (string) $log->error);
    }

    public function test_a_send_nobody_heard_back_from_is_marked_not_confirmed_never_failed(): void
    {
        $this->useMailerThatThrows('connection reset');
        $customer = $this->customer();

        try {
            $customer->notify(new RequestContractSignatureNotification('https://example.test/sign'));
        } catch (TransportException) {
            // the caller swallowed it, and nothing reported it
        }
        $this->assertSame(EmailLog::STATUS_SENDING, EmailLog::firstOrFail()->status);

        $this->app->terminate();

        // Nothing proved it failed, so it must not say "failed" (the first release did, wrongly, for real emails).
        $log = EmailLog::firstOrFail();
        $this->assertSame(EmailLog::STATUS_SENDING, $log->status);
        $this->assertSame('No confirmation was received from the email service.', $log->error);
    }

    public function test_a_broken_email_log_never_stops_an_email_from_going_out(): void
    {
        Schema::drop('email_logs');
        $customer = $this->customer();

        $customer->notify(new RequestContractSignatureNotification('https://example.test/sign'));

        $this->assertCount(1, Mail::mailer('array')->getSymfonyTransport()->messages());
    }

    public function test_staff_can_search_and_filter_the_log(): void
    {
        $a = $this->customer(['name' => 'Alice Alpha', 'email' => 'alice@example.com']);
        $b = $this->customer(['name' => 'Bob Beta', 'email' => 'bob@example.com']);
        $a->notify(new RequestContractSignatureNotification('https://example.test/a'));
        $b->notify(new RequestContractSignatureNotification('https://example.test/b'));
        EmailLog::where('to_address', 'bob@example.com')->update(['status' => EmailLog::STATUS_FAILED, 'error' => 'boom']);
        $admin = $this->admin();

        $all = $this->actingAs($admin, 'sanctum')->getJson('/api/admin/email-logs')->assertOk();
        $this->assertSame(2, $all->json('meta.total'));
        $this->assertSame('Bob Beta', $all->json('data.0.user.name')); // newest first

        $byAddress = $this->actingAs($admin, 'sanctum')->getJson('/api/admin/email-logs?search=alice@')->assertOk();
        $this->assertSame(['alice@example.com'], collect($byAddress->json('data'))->pluck('to_address')->all());

        $byName = $this->actingAs($admin, 'sanctum')->getJson('/api/admin/email-logs?search=Bob')->assertOk();
        $this->assertSame(['bob@example.com'], collect($byName->json('data'))->pluck('to_address')->all());

        $failed = $this->actingAs($admin, 'sanctum')->getJson('/api/admin/email-logs?status=failed')->assertOk();
        $this->assertSame(['bob@example.com'], collect($failed->json('data'))->pluck('to_address')->all());

        // A literal percent sign in the search is not a wildcard.
        $percent = $this->actingAs($admin, 'sanctum')->getJson('/api/admin/email-logs?search=%25')->assertOk();
        $this->assertSame(0, $percent->json('meta.total'));
    }

    public function test_a_customers_page_lists_what_was_sent_including_to_an_address_since_corrected(): void
    {
        $customer = $this->customer(['email' => 'typo@gmail.comt']);
        $customer->notify(new RequestContractSignatureNotification('https://example.test/sign'));
        $customer->update(['email' => 'typo@gmail.com']);
        $customer->notify(new RequestContractSignatureNotification('https://example.test/sign'));
        $other = $this->customer(['email' => 'other@example.com']);
        $other->notify(new RequestContractSignatureNotification('https://example.test/sign'));

        $response = $this->actingAs($this->admin(), 'sanctum')->getJson("/api/admin/customers/{$customer->id}/emails")->assertOk();

        $this->assertSame(['typo@gmail.com', 'typo@gmail.comt'], collect($response->json('data'))->pluck('to_address')->all());
    }

    public function test_only_staff_with_customer_access_can_read_the_log(): void
    {
        $customer = $this->customer();

        $this->actingAs($customer, 'sanctum')->getJson('/api/admin/email-logs')->assertForbidden();

        $restricted = User::factory()->create(['role' => User::ROLE_ADMIN]);
        AdminPermission::create(['user_id' => $restricted->id, 'permission' => AdminPermission::PAYMENT_TRACKING]);
        $this->actingAs($restricted, 'sanctum')->getJson('/api/admin/email-logs')->assertForbidden();
        $this->actingAs($restricted, 'sanctum')->getJson("/api/admin/customers/{$customer->id}/emails")->assertForbidden();
    }

    /** A mailer that accepts every message, built like the real ones: on AbstractTransport, which sends a copy of the message. */
    private function useMailerThatAccepts(): void
    {
        $this->app->forgetInstance('mail.manager');
        Mail::clearResolvedInstance('mail.manager');

        Mail::extend('accepting', fn () => new class extends AbstractTransport
        {
            protected function doSend(SentMessage $message): void {}

            public function __toString(): string
            {
                return 'accepting://';
            }
        });
        config(['mail.mailers.accepting' => ['transport' => 'accepting'], 'mail.default' => 'accepting']);
    }

    /** Swaps the default mailer for one whose every send throws, like the email service refusing the message. */
    private function useMailerThatThrows(string $message): void
    {
        // A fresh mail manager first, so no earlier mailer instance is reused; then teach it the new transport.
        $this->app->forgetInstance('mail.manager');
        Mail::clearResolvedInstance('mail.manager');

        Mail::extend('exploding', fn () => new class($message) extends AbstractTransport
        {
            public function __construct(private readonly string $message)
            {
                parent::__construct();
            }

            protected function doSend(SentMessage $message): void
            {
                throw new TransportException($this->message);
            }

            public function __toString(): string
            {
                return 'exploding://';
            }
        });
        config(['mail.mailers.exploding' => ['transport' => 'exploding'], 'mail.default' => 'exploding']);
    }

    protected function setUp(): void
    {
        parent::setUp();
        $this->app->forgetInstance(EmailLogger::class);
    }
}
