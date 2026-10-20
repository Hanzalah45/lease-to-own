<?php

namespace Tests\Feature;

use App\Models\AdminPermission;
use App\Models\LeaseAgreement;
use App\Models\Payment;
use App\Models\PaymentAuditLog;
use App\Models\PaymentLink;
use App\Models\PaymentPart;
use App\Models\User;
use App\Notifications\PaymentLinkNotification;
use App\Services\CardPricing;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Notifications\AnonymousNotifiable;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Stripe\ApiRequestor;
use Stripe\WebhookSignature;
use Tests\Support\FakeStripeHttpClient;
use Tests\TestCase;

/**
 * Payment links (client, Joel, 2026-10-19 checklist, section 6): staff make a link for an amount, send it, send it again, cancel it and see
 * its history; the customer opens it without logging in, sees exactly what they pay, and pays with a saved bank account or card.
 */
class PaymentLinkTest extends TestCase
{
    use RefreshDatabase;

    private FakeStripeHttpClient $stripe;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->stripe = new FakeStripeHttpClient;
        ApiRequestor::setHttpClient($this->stripe);
        config(['services.stripe.webhook_secret' => 'whsec_test_secret_for_specs_only', 'app.frontend_url' => 'https://pay.example.test']);
        $this->travelTo(Carbon::parse('2026-10-15 15:00:00', 'UTC'));
        $this->admin = User::factory()->create(['role' => User::ROLE_SUPER_ADMIN, 'name' => 'Stefany Staff']);
    }

    protected function tearDown(): void
    {
        ApiRequestor::setHttpClient(null);
        parent::tearDown();
    }

    private function lease(array $overrides = []): LeaseAgreement
    {
        $customer = User::factory()->create(['role' => User::ROLE_CUSTOMER, 'name' => 'Jane Doe', 'email' => 'jane@example.test']);
        $customer->customerProfile()->create(['stripe_customer_id' => 'cus_link']);

        return LeaseAgreement::factory()->pickedUp()->create(array_merge([
            'customer_id' => $customer->id, 'monthly_rental_payment' => 200, 'sales_tax_rate' => 0, 'ldw_selected' => false, 'ldw_amount' => 0,
            'cash_price' => 3000, 'term_months' => 36, 'additional_funds' => 0, 'autopay_enabled' => false,
            'stripe_bank_payment_method_id' => 'pm_bank', 'stripe_card_payment_method_id' => 'pm_card', 'autopay_primary_method' => 'card',
        ], $overrides));
    }

    private function due(LeaseAgreement $lease, array $overrides = []): Payment
    {
        return Payment::factory()->create(array_merge([
            'lease_agreement_id' => $lease->id, 'type' => Payment::TYPE_RENTAL, 'amount' => 200, 'due_date' => '2026-10-10', 'status' => Payment::STATUS_PENDING,
        ], $overrides));
    }

    private function make(Payment $payment, array $body = [], ?User $as = null): TestResponse
    {
        return $this->actingAs($as ?? $this->admin, 'sanctum')->postJson("/api/admin/payments/{$payment->id}/links", $body);
    }

    /** The secret in a link's address. */
    private function tokenOf(string $url): string
    {
        return Str::afterLast($url, '/pay/');
    }

    private function show(string $token): TestResponse
    {
        return $this->postJson('/api/pay-links/show', ['token' => $token]);
    }

    private function pay(string $token, array $body = []): TestResponse
    {
        return $this->postJson('/api/pay-links/pay', array_merge(['token' => $token, 'method' => 'card'], $body));
    }

    private function intent(string $id, string $status): array
    {
        return ['id' => $id, 'object' => 'payment_intent', 'status' => $status];
    }

    private function webhook(string $type, array $object): TestResponse
    {
        $payload = json_encode(['id' => 'evt_'.Str::random(16), 'object' => 'event', 'type' => $type, 'data' => ['object' => $object]]);

        return $this->call('POST', '/api/webhooks/stripe', [], [], [], [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_Stripe-Signature' => WebhookSignature::generateSignatureHeader($payload, 'whsec_test_secret_for_specs_only'),
        ], $payload);
    }

    private function cardTotalCents(int $bankCents): int
    {
        return CardPricing::cardCents($bankCents);
    }

    // ---- making and sending ----

    public function test_staff_make_a_link_for_a_payment_and_only_its_hash_is_kept(): void
    {
        $payment = $this->due($this->lease());

        $response = $this->make($payment)->assertCreated();

        $url = $response->json('url');
        $this->assertMatchesRegularExpression('#^https://pay\.example\.test/pay/[A-Za-z0-9]{48}$#', $url);
        $response->assertJsonPath('data.status', 'created')->assertJsonPath('data.amount', '200.00')->assertJsonPath('data.kind', 'payment')->assertJsonPath('mailed', null);
        $link = PaymentLink::firstOrFail();
        $this->assertSame($payment->id, $link->payment_id);
        $this->assertSame($this->admin->id, $link->created_by);
        $this->assertSame('2026-10-29', $link->expires_at->toDateString());
        $this->assertSame(hash('sha256', $this->tokenOf($url)), $link->token_hash);
        $this->assertStringNotContainsString($this->tokenOf($url), json_encode($link->getAttributes()));
        $this->assertArrayNotHasKey('token_hash', $response->json('data'));
        $this->assertStringContainsString('Monthly payment due October 10, 2026', $link->description);
        $this->assertSame(1, PaymentAuditLog::where('action', 'link.created')->count());
    }

    public function test_a_link_can_be_for_part_of_what_is_due_but_never_more_than_is_due(): void
    {
        $payment = $this->due($this->lease());

        $this->make($payment, ['amount' => 75.5])->assertCreated()->assertJsonPath('data.amount', '75.50');
        $this->make($payment, ['amount' => 200.01])->assertStatus(422)->assertJsonPath('message', 'At most $200.00 is still due on this payment.');
        $this->make($payment, ['amount' => 0])->assertStatus(422)->assertJsonValidationErrors('amount');
        $this->make($payment, ['email' => 'not-an-email'])->assertStatus(422)->assertJsonValidationErrors('email');

        $paid = $this->due($payment->leaseAgreement, ['status' => Payment::STATUS_PAID, 'paid_date' => '2026-10-01']);
        $this->make($paid)->assertStatus(422)->assertJsonPath('message', 'This payment is not waiting to be paid.');
        $extra = $this->due($payment->leaseAgreement, ['type' => Payment::TYPE_EXTRA_PAYMENT]);
        $this->make($extra)->assertStatus(422);
        $this->assertSame(1, PaymentLink::count());
    }

    public function test_sending_a_link_emails_it_logs_the_address_and_the_time_and_marks_it_sent(): void
    {
        Notification::fake();
        $payment = $this->due($this->lease());

        $response = $this->make($payment, ['send' => true])->assertCreated();

        $response->assertJsonPath('data.status', 'sent')->assertJsonPath('data.sent_to', 'jane@example.test')->assertJsonPath('data.send_count', 1)->assertJsonPath('mailed', true);
        $this->assertNotNull($response->json('data.first_sent_at'));
        $this->assertSame('jane@example.test', $response->json('data.sends.0.email'));
        $this->assertSame('sent', $response->json('data.sends.0.outcome'));
        $this->assertSame('Stefany Staff', $response->json('data.sends.0.sent_by.name'));
        Notification::assertSentOnDemand(PaymentLinkNotification::class, function ($notification, $channels, AnonymousNotifiable $notifiable) {
            $mail = $notification->toMail($notifiable);

            return $notifiable->routes['mail'] === 'jane@example.test'
                && $channels === ['mail']
                && str_contains($mail->actionUrl, 'https://pay.example.test/pay/')
                && str_contains(implode(' ', $mail->introLines), 'Monthly payment due October 10, 2026');
        });
    }

    public function test_a_link_can_go_to_another_address(): void
    {
        Notification::fake();
        $payment = $this->due($this->lease());

        $this->make($payment, ['send' => true, 'email' => 'spouse@example.test'])->assertCreated()->assertJsonPath('data.sent_to', 'spouse@example.test');

        Notification::assertSentOnDemand(PaymentLinkNotification::class, fn ($n, $c, AnonymousNotifiable $notifiable) => $notifiable->routes['mail'] === 'spouse@example.test');
    }

    public function test_sending_again_makes_a_new_secret_and_the_old_one_stops_working(): void
    {
        Notification::fake();
        $payment = $this->due($this->lease());
        $first = $this->make($payment, ['send' => true])->assertCreated();
        $oldToken = $this->tokenOf($first->json('url'));
        $linkId = $first->json('data.id');
        $this->show($oldToken)->assertOk();

        $this->travelTo(Carbon::parse('2026-10-20 15:00:00', 'UTC'));
        $again = $this->actingAs($this->admin, 'sanctum')->postJson("/api/admin/payment-links/{$linkId}/resend", ['email' => 'jane.work@example.test'])->assertOk();

        $again->assertJsonPath('data.send_count', 2)->assertJsonPath('data.sent_to', 'jane.work@example.test')->assertJsonPath('data.status', 'sent');
        $this->assertCount(2, $again->json('data.sends'));
        $this->assertSame(['jane@example.test', 'jane.work@example.test'], array_column($again->json('data.sends'), 'email'));
        // Counted from the day it was sent again.
        $this->assertSame('2026-11-03', PaymentLink::firstOrFail()->expires_at->toDateString());
        $this->show($oldToken)->assertNotFound();
        $this->show($this->tokenOf($again->json('url')))->assertOk();
    }

    public function test_a_resend_without_an_address_goes_to_the_last_one_and_a_paid_or_cancelled_link_is_not_sent_again(): void
    {
        Notification::fake();
        $payment = $this->due($this->lease());
        $linkId = $this->make($payment, ['send' => true, 'email' => 'spouse@example.test'])->assertCreated()->json('data.id');

        $this->actingAs($this->admin, 'sanctum')->postJson("/api/admin/payment-links/{$linkId}/resend")->assertOk()->assertJsonPath('data.sent_to', 'spouse@example.test');

        $this->actingAs($this->admin, 'sanctum')->postJson("/api/admin/payment-links/{$linkId}/cancel")->assertOk();
        $this->actingAs($this->admin, 'sanctum')->postJson("/api/admin/payment-links/{$linkId}/resend")->assertStatus(422)->assertJsonPath('message', 'This link was cancelled.');
    }

    // ---- ending a link ----

    public function test_cancelling_a_link_ends_it_even_for_someone_who_copied_it(): void
    {
        $payment = $this->due($this->lease());
        $made = $this->make($payment)->assertCreated();
        $token = $this->tokenOf($made->json('url'));

        $this->actingAs($this->admin, 'sanctum')->postJson("/api/admin/payment-links/{$made->json('data.id')}/cancel")->assertOk()->assertJsonPath('data.status', 'cancelled');

        $this->show($token)->assertNotFound();
        $this->pay($token)->assertNotFound();
        $this->assertNotNull(PaymentLink::firstOrFail()->cancelled_at);
        $this->actingAs($this->admin, 'sanctum')->postJson("/api/admin/payment-links/{$made->json('data.id')}/cancel")->assertStatus(422);
    }

    public function test_a_link_expires_and_says_so_and_cannot_be_paid(): void
    {
        $payment = $this->due($this->lease());
        $made = $this->make($payment, ['send' => true])->assertCreated();
        $token = $this->tokenOf($made->json('url'));

        $this->travelTo(Carbon::parse('2026-10-30 15:00:00', 'UTC'));

        $page = $this->show($token)->assertOk();
        $this->assertSame('expired', $page->json('data.status'));
        $this->assertSame('This link has expired. Please contact us for a new one.', $page->json('data.blocker'));
        $this->pay($token)->assertStatus(422)->assertJsonPath('message', 'This link has expired. Please contact us for a new one.');
        $this->assertCount(0, $this->stripe->requests);

        $list = $this->actingAs($this->admin, 'sanctum')->getJson("/api/admin/lease-agreements/{$payment->lease_agreement_id}/payment-links")->assertOk();
        $this->assertSame('expired', $list->json('data.0.effective_status'));

        // Sending it again brings it back.
        $again = $this->actingAs($this->admin, 'sanctum')->postJson("/api/admin/payment-links/{$made->json('data.id')}/resend")->assertOk();
        $this->assertSame('sent', $again->json('data.effective_status'));
    }

    // ---- what the customer sees ----

    public function test_the_page_shows_what_is_due_and_both_prices_and_records_that_it_was_opened_once(): void
    {
        Notification::fake();
        $lease = $this->lease();
        $payment = $this->due($lease);
        $token = $this->tokenOf($this->make($payment, ['send' => true, 'amount' => 120])->json('url'));

        $page = $this->show($token)->assertOk();

        $this->assertSame('Jane Doe', $page->json('data.customer_name'));
        $this->assertSame('payment', $page->json('data.kind'));
        $this->assertNull($page->json('data.blocker'));
        $this->assertEqualsWithDelta(120.0, $page->json('data.amount'), 0.001);
        $this->assertEqualsWithDelta(120.0, $page->json('data.prices.bank'), 0.001);
        $this->assertEqualsWithDelta(CardPricing::card(120.0), $page->json('data.prices.card'), 0.001);
        $this->assertSame(['bank', 'card'], $page->json('data.available_methods'));
        $this->assertSame(300, $page->json('data.card_fee_bps'));
        $this->assertNull($page->json('data.setup_url'));

        $link = PaymentLink::firstOrFail();
        $this->assertSame('opened', $link->status);
        $openedAt = $link->opened_at;
        $this->assertNotNull($openedAt);
        $this->travelTo(now()->addHour());
        $this->show($token)->assertOk();
        $this->assertEquals($openedAt, $link->fresh()->opened_at, 'Only the first opening counts.');
        // Not a staff screen: the page carries nothing about staff or the secret.
        $this->assertStringNotContainsString('Stefany', $page->getContent());
        $this->assertStringNotContainsString($token, $page->getContent());
    }

    public function test_a_customer_with_nothing_saved_is_sent_to_add_a_method_first(): void
    {
        $lease = $this->lease(['stripe_bank_payment_method_id' => null, 'stripe_card_payment_method_id' => null]);
        $token = $this->tokenOf($this->make($this->due($lease))->json('url'));

        $page = $this->show($token)->assertOk();

        $this->assertSame([], $page->json('data.available_methods'));
        $this->assertStringContainsString('/setup-autopay?', $page->json('data.setup_url'));
    }

    public function test_a_locked_method_is_not_offered_and_an_unknown_secret_is_refused(): void
    {
        $lease = $this->lease(['card_locked_at' => now(), 'card_lock_reason' => 'Fraud']);
        $token = $this->tokenOf($this->make($this->due($lease))->json('url'));

        $this->assertSame(['bank'], $this->show($token)->json('data.available_methods'));
        $this->postJson('/api/pay-links/show', ['token' => Str::random(48)])->assertNotFound();
        $this->postJson('/api/pay-links/show', [])->assertStatus(422);
        $this->postJson('/api/pay-links/show', ['token' => str_repeat('a', 129)])->assertStatus(422);
    }

    public function test_the_page_says_so_when_what_the_link_was_for_has_been_paid_some_other_way(): void
    {
        $payment = $this->due($this->lease());
        $token = $this->tokenOf($this->make($payment)->json('url'));
        $payment->update(['status' => Payment::STATUS_PAID, 'paid_date' => '2026-10-15']);

        $page = $this->show($token)->assertOk();

        $this->assertSame('This has already been paid. Thank you.', $page->json('data.blocker'));
        $this->pay($token)->assertStatus(422);
        $this->assertCount(0, $this->stripe->requests);
    }

    // ---- paying ----

    public function test_paying_the_whole_amount_by_card_pays_the_payment_and_the_link(): void
    {
        $lease = $this->lease();
        $payment = $this->due($lease);
        $token = $this->tokenOf($this->make($payment)->json('url'))
            ?: '';
        $this->stripe->queue($this->intent('pi_link_card', 'succeeded'));

        $response = $this->pay($token, ['method' => 'card', 'expected_total_cents' => $this->cardTotalCents(20000)])->assertOk();

        $response->assertJsonPath('data.status', 'paid');
        $this->assertSame($this->cardTotalCents(20000), (int) $this->stripe->requests[0]['params']['amount']);
        $payment->refresh();
        $this->assertSame(Payment::STATUS_PAID, $payment->status);
        $this->assertSame('card', $payment->method);
        $link = PaymentLink::firstOrFail();
        $this->assertSame('paid', $link->status);
        $this->assertNotNull($link->paid_at);
        $part = PaymentPart::firstOrFail();
        $this->assertSame($link->id, $part->payment_link_id);
        $this->assertSame('customer', $part->started_by);
        $this->assertSame('paid', $response->json('data.link.status'));
        $this->assertSame(1, PaymentAuditLog::where('action', 'link.paid')->count());
    }

    public function test_paying_part_by_bank_leaves_the_rest_due_and_the_link_is_paid_when_the_bank_confirms(): void
    {
        $payment = $this->due($this->lease());
        $token = $this->tokenOf($this->make($payment, ['amount' => 80])->json('url'));
        $this->stripe->queue($this->intent('pi_link_bank', 'processing'));

        $this->pay($token, ['method' => 'bank'])->assertOk()->assertJsonPath('data.status', 'processing')->assertJsonPath('data.link.status', 'processing');

        $this->assertSame(Payment::STATUS_PENDING, $payment->fresh()->status);
        $link = PaymentLink::firstOrFail();
        $this->assertSame('processing', $link->status);
        // While it clears, the link cannot be used a second time.
        $this->pay($token, ['method' => 'bank'])->assertStatus(422);
        $this->actingAs($this->admin, 'sanctum')->postJson("/api/admin/payment-links/{$link->id}/cancel")->assertStatus(422);

        $this->webhook('payment_intent.succeeded', $this->intent('pi_link_bank', 'succeeded'))->assertOk();

        $this->assertSame('paid', $link->fresh()->status);
        $this->assertEqualsWithDelta(120.0, $payment->fresh()->remainingAmount(), 0.001);
    }

    public function test_a_bank_payment_returned_later_marks_the_link_failed_and_it_can_be_tried_again(): void
    {
        $payment = $this->due($this->lease());
        $token = $this->tokenOf($this->make($payment, ['amount' => 80])->json('url'));
        $this->stripe->queue($this->intent('pi_link_bounce', 'processing'));
        $this->pay($token, ['method' => 'bank'])->assertOk();

        $this->webhook('payment_intent.payment_failed', $this->intent('pi_link_bounce', 'requires_payment_method') + [
            'last_payment_error' => ['code' => 'insufficient_funds', 'message' => 'The account has insufficient funds.'],
        ])->assertOk();

        $link = PaymentLink::firstOrFail();
        $this->assertSame('failed', $link->status);
        $this->assertSame('The account has insufficient funds.', $link->failure_message);

        $this->stripe->queue($this->intent('pi_link_retry', 'succeeded'));
        $this->pay($token, ['method' => 'card'])->assertOk()->assertJsonPath('data.status', 'paid');
        $this->assertSame('paid', $link->fresh()->status);
        $this->assertNull($link->fresh()->failure_message);
    }

    public function test_a_declined_card_marks_the_link_failed_with_the_reason_and_it_can_be_tried_again(): void
    {
        $payment = $this->due($this->lease());
        $token = $this->tokenOf($this->make($payment)->json('url'));
        $this->stripe->queue(['error' => [
            'message' => 'Your card was declined.', 'type' => 'card_error', 'code' => 'card_declined', 'decline_code' => 'generic_decline',
            'payment_intent' => $this->intent('pi_link_declined', 'requires_payment_method'),
        ]], 402);

        $this->pay($token)->assertStatus(422)->assertJsonPath('message', 'Your card was declined.');

        $link = PaymentLink::firstOrFail();
        $this->assertSame('failed', $link->status);
        $this->assertSame('Your card was declined.', $link->failure_message);
        $this->assertSame('failed', $this->show($token)->json('data.status'));
        $this->assertNull($this->show($token)->json('data.blocker'), 'A failed link can be tried again.');

        $this->stripe->queue($this->intent('pi_link_ok', 'succeeded'));
        $this->pay($token)->assertOk()->assertJsonPath('data.status', 'paid');
    }

    public function test_the_price_the_customer_saw_must_match_and_a_method_must_be_chosen(): void
    {
        $payment = $this->due($this->lease());
        $token = $this->tokenOf($this->make($payment)->json('url'));

        $this->pay($token, ['method' => 'card', 'expected_total_cents' => 20000])->assertStatus(409)->assertJsonPath('message', 'The amount changed. Please review the new total and try again.');
        $this->pay($token, ['method' => 'cash'])->assertStatus(422)->assertJsonValidationErrors('method');
        $this->postJson('/api/pay-links/pay', ['token' => $token])->assertStatus(422)->assertJsonValidationErrors('method');
        $this->assertCount(0, $this->stripe->requests);
        $this->assertSame('created', PaymentLink::firstOrFail()->status);
    }

    public function test_a_link_never_takes_more_than_is_still_due_when_something_was_paid_meanwhile(): void
    {
        $payment = $this->due($this->lease());
        $token = $this->tokenOf($this->make($payment, ['amount' => 150])->json('url'));
        // $100 came in cash in the office: only $100 is still due, so the link takes $100, not $150.
        $this->actingAs($this->admin, 'sanctum')->postJson("/api/admin/payments/{$payment->id}/parts", ['method' => 'cash', 'amount' => 100])->assertCreated();

        $page = $this->show($token)->assertOk();
        $this->assertEqualsWithDelta(100.0, $page->json('data.amount'), 0.001);

        $this->stripe->queue($this->intent('pi_link_rest', 'succeeded'));
        $this->pay($token, ['method' => 'bank'])->assertOk();
        $this->assertSame(10000, (int) $this->stripe->requests[0]['params']['amount']);
        $this->assertSame(Payment::STATUS_PAID, $payment->fresh()->status);
    }

    public function test_a_link_for_an_extra_payment_pays_toward_the_early_purchase_price(): void
    {
        Notification::fake();
        $lease = $this->lease();
        $made = $this->actingAs($this->admin, 'sanctum')->postJson("/api/admin/lease-agreements/{$lease->id}/payment-links/extra", ['amount' => 100])->assertCreated();
        $made->assertJsonPath('data.kind', 'extra')->assertJsonPath('data.description', 'An extra payment toward your early purchase price');
        $token = $this->tokenOf($made->json('url'));
        $this->assertEqualsWithDelta(100.0, $this->show($token)->json('data.amount'), 0.001);
        $this->stripe->queue($this->intent('pi_link_extra', 'succeeded'));

        $this->pay($token, ['method' => 'card'])->assertOk()->assertJsonPath('data.status', 'paid');

        $extra = Payment::where('type', Payment::TYPE_EXTRA_PAYMENT)->firstOrFail();
        $this->assertSame(Payment::STATUS_PAID, $extra->status);
        $this->assertSame(PaymentLink::firstOrFail()->id, $extra->payment_link_id);
        $this->assertSame($extra->id, PaymentLink::firstOrFail()->payment_id);
        $this->assertSame('paid', PaymentLink::firstOrFail()->status);
        $this->assertEqualsWithDelta(100.0, (float) $lease->fresh()->additional_funds, 0.001);
    }

    public function test_an_extra_payment_link_is_refused_where_an_extra_payment_is_not_allowed(): void
    {
        $lease = $this->lease();
        $this->due($lease, ['due_date' => '2026-10-01']);

        $this->actingAs($this->admin, 'sanctum')->postJson("/api/admin/lease-agreements/{$lease->id}/payment-links/extra", ['amount' => 100])
            ->assertStatus(422)
            ->assertJsonPath('message', fn ($m) => str_contains($m, 'pay the amount that is due first'));
        $this->actingAs($this->admin, 'sanctum')->postJson("/api/admin/lease-agreements/{$lease->id}/payment-links/extra", ['amount' => 1])->assertStatus(422);
        $this->assertSame(0, PaymentLink::count());
    }

    // ---- history and access ----

    public function test_a_customers_link_history_lists_every_link_and_every_send_newest_first(): void
    {
        Notification::fake();
        $lease = $this->lease();
        $one = $this->due($lease);
        $two = $this->due($lease, ['due_date' => '2026-11-10']);
        $this->make($one, ['send' => true])->assertCreated();
        $second = $this->make($two)->assertCreated()->json('data.id');
        $this->actingAs($this->admin, 'sanctum')->postJson("/api/admin/payment-links/{$second}/resend", ['email' => 'a@example.test'])->assertOk();
        $this->actingAs($this->admin, 'sanctum')->postJson("/api/admin/payment-links/{$second}/resend", ['email' => 'b@example.test'])->assertOk();

        $list = $this->actingAs($this->admin, 'sanctum')->getJson("/api/admin/lease-agreements/{$lease->id}/payment-links")->assertOk();

        $this->assertSame([$second, PaymentLink::orderBy('id')->first()->id], array_column($list->json('data'), 'id'));
        $this->assertSame(['a@example.test', 'b@example.test'], array_column($list->json('data.0.sends'), 'email'));
        $this->assertSame(2, $list->json('data.0.send_count'));
        $this->assertSame('Stefany Staff', $list->json('data.0.created_by.name'));
    }

    public function test_only_staff_with_payment_tracking_can_use_the_staff_endpoints(): void
    {
        $lease = $this->lease();
        $payment = $this->due($lease);
        $linkId = $this->make($payment)->json('data.id');
        $reviewer = User::factory()->create(['role' => User::ROLE_ADMIN]);
        AdminPermission::create(['user_id' => $reviewer->id, 'permission' => AdminPermission::APPLICATION_REVIEW]);

        foreach ([$lease->customer, $reviewer] as $user) {
            $this->make($payment, [], $user)->assertForbidden();
            $this->actingAs($user, 'sanctum')->postJson("/api/admin/lease-agreements/{$lease->id}/payment-links/extra", ['amount' => 50])->assertForbidden();
            $this->actingAs($user, 'sanctum')->getJson("/api/admin/lease-agreements/{$lease->id}/payment-links")->assertForbidden();
            $this->actingAs($user, 'sanctum')->postJson("/api/admin/payment-links/{$linkId}/resend")->assertForbidden();
            $this->actingAs($user, 'sanctum')->postJson("/api/admin/payment-links/{$linkId}/cancel")->assertForbidden();
        }
    }
}
