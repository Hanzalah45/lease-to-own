<?php

namespace Tests\Feature;

use App\Models\LeaseAgreement;
use App\Models\PaymentAuditLog;
use App\Models\User;
use App\Notifications\BankAccountVerificationNotification;
use App\Services\PaymentMethodSigner;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Stripe\ApiRequestor;
use Stripe\WebhookSignature;
use Tests\Support\FakeStripeHttpClient;
use Tests\TestCase;

/**
 * A bank account that needs two small test deposits (client, Joel, 2026-10-19 checklist, section 2). A customer who types an account
 * number instead of logging in to their bank cannot be confirmed instantly: Stripe sends two small deposits that arrive in one to two
 * business days, and the customer confirms the amounts on Stripe's page. Until then the account waits, apart from the one the lease
 * charges. Stripe is faked at the HTTP layer; nothing here touches the network.
 */
class BankMicrodepositTest extends TestCase
{
    use RefreshDatabase;

    private FakeStripeHttpClient $stripe;

    private LeaseAgreement $lease;

    protected function setUp(): void
    {
        parent::setUp();
        $this->stripe = new FakeStripeHttpClient;
        ApiRequestor::setHttpClient($this->stripe);
        config(['services.stripe.webhook_secret' => 'whsec_test_secret_for_specs_only']);
        $this->travelTo(Carbon::parse('2026-10-20 15:00:00', 'UTC'));

        $customer = User::factory()->create(['role' => User::ROLE_CUSTOMER, 'name' => 'Jane Doe', 'email' => 'jane@example.test']);
        $customer->customerProfile()->create(['stripe_customer_id' => 'cus_bank']);
        $this->lease = LeaseAgreement::factory()->pickedUp()->create(['customer_id' => $customer->id, 'stripe_bank_payment_method_id' => null, 'stripe_card_payment_method_id' => 'pm_card']);
    }

    protected function tearDown(): void
    {
        ApiRequestor::setHttpClient(null);
        parent::tearDown();
    }

    /** What Stripe says about an account that sent its deposits and waits for them to be confirmed. */
    private function waitingIntent(string $id = 'seti_bank', array $overrides = []): array
    {
        return array_replace_recursive([
            'id' => $id, 'object' => 'setup_intent', 'customer' => 'cus_bank', 'status' => 'requires_action', 'payment_method' => 'pm_new_bank',
            'next_action' => [
                'type' => 'verify_with_microdeposits',
                'verify_with_microdeposits' => [
                    'arrival_date' => Carbon::parse('2026-10-23 04:00:00', 'UTC')->timestamp,
                    'hosted_verification_url' => 'https://payments.stripe.com/microdeposit/sacs_test_abc',
                    'microdeposit_type' => 'amounts',
                ],
            ],
        ], $overrides);
    }

    private function verifiedIntent(string $id = 'seti_bank'): array
    {
        return ['id' => $id, 'object' => 'setup_intent', 'customer' => 'cus_bank', 'status' => 'succeeded', 'payment_method' => 'pm_new_bank'];
    }

    private function bankMethod(): array
    {
        return ['id' => 'pm_new_bank', 'object' => 'payment_method', 'type' => 'us_bank_account', 'us_bank_account' => ['bank_name' => 'STRIPE TEST BANK', 'last4' => '6789']];
    }

    private function asCustomer(): self
    {
        return $this->actingAs($this->lease->customer, 'sanctum');
    }

    private function base(): string
    {
        return "/api/customer/lease-agreements/{$this->lease->id}/payment-methods";
    }

    private function startPending(string $id = 'seti_bank'): TestResponse
    {
        $this->stripe->queue($this->waitingIntent($id));

        return $this->asCustomer()->postJson($this->base().'/bank-pending', ['setup_intent_id' => $id]);
    }

    private function webhook(string $type, array $object): TestResponse
    {
        $payload = json_encode(['id' => 'evt_'.Str::random(16), 'object' => 'event', 'type' => $type, 'data' => ['object' => $object]]);

        return $this->call('POST', '/api/webhooks/stripe', [], [], [], [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_Stripe-Signature' => WebhookSignature::generateSignatureHeader($payload, 'whsec_test_secret_for_specs_only'),
        ], $payload);
    }

    // ---- an account that waits for its deposits ----

    public function test_an_account_waiting_for_its_deposits_is_remembered_and_the_customer_is_told(): void
    {
        Notification::fake();

        $response = $this->startPending()->assertOk();

        $response->assertJsonPath('data.bank_account_added', false)
            ->assertJsonPath('data.bank_pending.arrives_by', '2026-10-23')
            ->assertJsonPath('data.bank_pending.kind', 'amounts')
            ->assertJsonPath('data.bank_pending.verify_url', 'https://payments.stripe.com/microdeposit/sacs_test_abc');
        $lease = $this->lease->fresh();
        $this->assertSame('seti_bank', $lease->bank_pending_setup_intent_id);
        $this->assertNull($lease->stripe_bank_payment_method_id, 'The account cannot be charged until it is confirmed.');
        $this->assertSame(1, PaymentAuditLog::where('action', 'method.pending')->count());
        Notification::assertSentTo($this->lease->customer, BankAccountVerificationNotification::class, function ($n, $channels, $notifiable) {
            $mail = $n->toMail($notifiable);

            return $channels === ['database', 'mail']
                && $mail->subject === 'Confirm your bank account'
                && $mail->actionUrl === 'https://payments.stripe.com/microdeposit/sacs_test_abc'
                && str_contains(implode(' ', $mail->introLines), 'two small deposits')
                && str_contains(implode(' ', $mail->introLines), 'October 23, 2026');
        });
        // The page the customer reads again later still says so.
        $this->asCustomer()->getJson($this->base())->assertOk()->assertJsonPath('data.bank_pending.arrives_by', '2026-10-23');
    }

    public function test_a_code_deposit_says_so_in_the_email(): void
    {
        Notification::fake();
        $this->stripe->queue($this->waitingIntent('seti_bank', ['next_action' => ['verify_with_microdeposits' => ['microdeposit_type' => 'descriptor_code']]]));

        $this->asCustomer()->postJson($this->base().'/bank-pending', ['setup_intent_id' => 'seti_bank'])->assertOk()->assertJsonPath('data.bank_pending.kind', 'descriptor_code');

        Notification::assertSentTo($this->lease->customer, BankAccountVerificationNotification::class, fn ($n, $c, $notifiable) => str_contains(implode(' ', $n->toMail($notifiable)->introLines), 'a small deposit'));
    }

    public function test_only_an_account_that_really_waits_for_deposits_is_accepted(): void
    {
        // Already confirmed: nothing to wait for.
        $this->stripe->queue($this->verifiedIntent());
        $this->asCustomer()->postJson($this->base().'/bank-pending', ['setup_intent_id' => 'seti_bank'])->assertStatus(422)->assertJsonPath('message', 'That bank account is not waiting for small deposits.');
        // Waiting for something else.
        $this->stripe->queue($this->waitingIntent('seti_bank', ['next_action' => ['type' => 'redirect_to_url']]));
        $this->asCustomer()->postJson($this->base().'/bank-pending', ['setup_intent_id' => 'seti_bank'])->assertStatus(422);
        // Somebody else's.
        $this->stripe->queue($this->waitingIntent('seti_bank', ['customer' => 'cus_someone_else']));
        $this->asCustomer()->postJson($this->base().'/bank-pending', ['setup_intent_id' => 'seti_bank'])->assertStatus(422)->assertJsonPath('message', 'That bank account does not belong to this account.');
        $this->asCustomer()->postJson($this->base().'/bank-pending', [])->assertStatus(422)->assertJsonValidationErrors('setup_intent_id');

        $this->assertNull($this->lease->fresh()->bank_pending_setup_intent_id);
    }

    public function test_only_the_customer_of_the_lease_can_use_the_pending_endpoints(): void
    {
        $other = User::factory()->create(['role' => User::ROLE_CUSTOMER]);

        $this->actingAs($other, 'sanctum')->postJson($this->base().'/bank-pending', ['setup_intent_id' => 'seti_bank'])->assertNotFound();
        $this->actingAs($other, 'sanctum')->postJson($this->base().'/bank-pending/check')->assertNotFound();
        $this->actingAs($other, 'sanctum')->postJson($this->base().'/bank-pending/cancel')->assertNotFound();
    }

    public function test_starting_again_drops_the_account_that_was_waiting_before(): void
    {
        Notification::fake();
        $this->startPending('seti_old')->assertOk();

        $this->startPending('seti_new')->assertOk();

        $this->assertSame('seti_new', $this->lease->fresh()->bank_pending_setup_intent_id);
        $paths = array_map(fn ($r) => parse_url($r['url'], PHP_URL_PATH), $this->stripe->requests);
        $this->assertContains('/v1/setup_intents/seti_old/cancel', $paths);
    }

    // ---- the customer confirms ----

    public function test_when_stripe_says_the_deposits_were_confirmed_the_account_is_saved(): void
    {
        Notification::fake();
        $this->startPending()->assertOk();
        // Stripe is asked where it stands (check), then the saved account is read again before it is attached.
        $this->stripe->queue($this->verifiedIntent())->queue($this->verifiedIntent())->queue($this->bankMethod());

        $response = $this->asCustomer()->postJson($this->base().'/bank-pending/check')->assertOk();

        $response->assertJsonPath('result.state', 'verified')
            ->assertJsonPath('data.bank_account_added', true)
            ->assertJsonPath('data.bank_pending', null);
        $lease = $this->lease->fresh();
        $this->assertSame('pm_new_bank', $lease->stripe_bank_payment_method_id);
        $this->assertNull($lease->bank_pending_setup_intent_id);
        $this->assertNull($lease->bank_pending_verify_url);
        $this->assertSame(1, PaymentAuditLog::where('action', 'method.saved')->count());
        Notification::assertSentTo($this->lease->customer, BankAccountVerificationNotification::class, fn ($n, $c, $notifiable) => $n->toMail($notifiable)->subject === 'Your bank account is confirmed');
    }

    public function test_an_account_that_is_still_waiting_stays_waiting(): void
    {
        Notification::fake();
        $this->startPending()->assertOk();
        $this->stripe->queue($this->waitingIntent());

        $response = $this->asCustomer()->postJson($this->base().'/bank-pending/check')->assertOk();

        $response->assertJsonPath('result.state', 'waiting')->assertJsonPath('data.bank_account_added', false);
        $this->assertNotNull($response->json('data.bank_pending'));
        $this->assertSame('seti_bank', $this->lease->fresh()->bank_pending_setup_intent_id);
    }

    public function test_an_account_that_could_not_be_confirmed_is_cleared_and_the_customer_is_told(): void
    {
        Notification::fake();
        $this->lease->update(['stripe_bank_payment_method_id' => 'pm_old_bank']);
        $this->startPending()->assertOk();
        $this->stripe->queue(['id' => 'seti_bank', 'object' => 'setup_intent', 'customer' => 'cus_bank', 'status' => 'requires_payment_method']);

        $response = $this->asCustomer()->postJson($this->base().'/bank-pending/check')->assertOk();

        $response->assertJsonPath('result.state', 'failed')->assertJsonPath('data.bank_pending', null);
        // The account that was on file stays as it was.
        $this->assertSame('pm_old_bank', $this->lease->fresh()->stripe_bank_payment_method_id);
        $this->assertSame(1, PaymentAuditLog::where('action', 'method.pending_failed')->count());
        Notification::assertSentTo($this->lease->customer, BankAccountVerificationNotification::class, fn ($n, $c, $notifiable) => $n->toMail($notifiable)->subject === 'Your bank account could not be confirmed');
        // Nothing waits any more.
        $this->asCustomer()->postJson($this->base().'/bank-pending/check')->assertStatus(422);
    }

    public function test_the_customer_can_drop_the_waiting_account_to_use_another(): void
    {
        Notification::fake();
        $this->startPending()->assertOk();

        $this->asCustomer()->postJson($this->base().'/bank-pending/cancel')->assertOk()->assertJsonPath('data.bank_pending', null);

        $this->assertNull($this->lease->fresh()->bank_pending_setup_intent_id);
        $paths = array_map(fn ($r) => parse_url($r['url'], PHP_URL_PATH), $this->stripe->requests);
        $this->assertContains('/v1/setup_intents/seti_bank/cancel', $paths);
        $this->assertSame(1, PaymentAuditLog::where('action', 'method.pending_cancelled')->count());
        $this->asCustomer()->postJson($this->base().'/bank-pending/cancel')->assertStatus(422);
    }

    // ---- Stripe tells us ----

    public function test_stripe_telling_us_the_account_was_confirmed_saves_it_without_the_customer_doing_anything(): void
    {
        Notification::fake();
        $this->startPending()->assertOk();
        $this->stripe->queue($this->verifiedIntent())->queue($this->bankMethod());

        $this->webhook('setup_intent.succeeded', $this->verifiedIntent())->assertOk();

        $lease = $this->lease->fresh();
        $this->assertSame('pm_new_bank', $lease->stripe_bank_payment_method_id);
        $this->assertNull($lease->bank_pending_setup_intent_id);
        $audit = PaymentAuditLog::where('action', 'method.saved')->firstOrFail();
        $this->assertSame('system', $audit->actor_type);
        Notification::assertSentToTimes($this->lease->customer, BankAccountVerificationNotification::class, 2);

        // The same news again, or the customer pressing the button after it, does nothing more.
        $calls = count($this->stripe->requests);
        $this->webhook('setup_intent.succeeded', $this->verifiedIntent())->assertOk();
        $this->assertCount($calls, $this->stripe->requests);
        Notification::assertSentToTimes($this->lease->customer, BankAccountVerificationNotification::class, 2);
    }

    public function test_stripe_telling_us_it_failed_or_ran_out_clears_the_account_and_tells_the_customer(): void
    {
        foreach (['setup_intent.setup_failed', 'setup_intent.canceled'] as $type) {
            Notification::fake();
            $this->startPending()->assertOk();

            $this->webhook($type, ['id' => 'seti_bank', 'object' => 'setup_intent', 'customer' => 'cus_bank', 'status' => 'requires_payment_method'])->assertOk();

            $this->assertNull($this->lease->fresh()->bank_pending_setup_intent_id, $type);
            Notification::assertSentTo($this->lease->customer, BankAccountVerificationNotification::class, fn ($n, $c, $notifiable) => $n->toMail($notifiable)->subject === 'Your bank account could not be confirmed');
        }
    }

    public function test_setup_events_for_cards_and_instant_bank_setups_are_ignored(): void
    {
        $this->webhook('setup_intent.succeeded', $this->verifiedIntent('seti_a_card'))->assertOk();
        $this->webhook('setup_intent.setup_failed', ['id' => 'seti_other', 'object' => 'setup_intent'])->assertOk();

        $this->assertCount(0, $this->stripe->requests);
        $this->assertSame(0, PaymentAuditLog::count());
    }

    // ---- staff and the signed link ----

    public function test_staff_see_that_an_account_is_waiting_but_never_the_confirmation_page(): void
    {
        $this->startPending()->assertOk();
        $admin = User::factory()->create(['role' => User::ROLE_SUPER_ADMIN]);
        $this->stripe->queue(['id' => 'pm_card', 'object' => 'payment_method', 'type' => 'card', 'card' => ['brand' => 'visa', 'last4' => '4242', 'exp_month' => 1, 'exp_year' => 2030]]);

        $response = $this->actingAs($admin, 'sanctum')->getJson("/api/admin/lease-agreements/{$this->lease->id}/autopay-methods")->assertOk();

        $this->assertSame('2026-10-23', $response->json('data.bank_pending.arrives_by'));
        $this->assertArrayNotHasKey('verify_url', $response->json('data.bank_pending'));
        $this->assertStringNotContainsString('sacs_test_abc', $response->getContent());
        // The lease itself never carries it either.
        $this->assertArrayNotHasKey('bank_pending_verify_url', $this->lease->fresh()->toArray());
    }

    public function test_a_guest_customer_does_the_same_through_the_signed_link(): void
    {
        Notification::fake();
        parse_str((string) parse_url(PaymentMethodSigner::urlFor($this->lease->customer, $this->lease), PHP_URL_QUERY), $params);
        $this->stripe->queue($this->waitingIntent());

        $this->postJson('/api/payment-methods/verify-bank-pending', $params + ['setup_intent_id' => 'seti_bank'])
            ->assertOk()
            ->assertJsonPath('data.bank_pending.arrives_by', '2026-10-23');

        $this->postJson('/api/payment-methods/verify-show', $params)->assertOk()->assertJsonPath('data.bank_pending.kind', 'amounts');
        $this->stripe->queue($this->verifiedIntent())->queue($this->verifiedIntent())->queue($this->bankMethod());
        $this->postJson('/api/payment-methods/verify-bank-pending-check', $params)->assertOk()->assertJsonPath('result.state', 'verified')->assertJsonPath('data.bank_account_added', true);
        $this->postJson('/api/payment-methods/verify-bank-pending-check', array_merge($params, ['signature' => 'forged']))->assertStatus(422);
    }
}
