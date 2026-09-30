<?php

namespace Tests\Feature;

use App\Models\LeaseAgreement;
use App\Models\Payment;
use App\Models\StripeWebhookEvent;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Stripe\WebhookSignature;
use Tests\TestCase;

/**
 * Covers the part of the Stripe integration that doesn't depend on the still-
 * open architecture questions (payment method type, AutoPay auto vs manual)
 * or on real API keys: verifying a webhook actually came from Stripe, and
 * applying a PaymentIntent's outcome to whichever scheduled Payment row it
 * was for. No test ever needs a real Stripe secret — WebhookSignature's own
 * generateSignatureHeader() helper (shipped by Stripe's SDK for exactly this)
 * signs a fake payload the same way Stripe would.
 */
class StripeWebhookTest extends TestCase
{
    use RefreshDatabase;

    private const WEBHOOK_SECRET = 'whsec_test_secret_for_specs_only';

    private function postWebhook(array $event): TestResponse
    {
        $payload = json_encode($event);
        $signature = WebhookSignature::generateSignatureHeader($payload, self::WEBHOOK_SECRET);

        return $this->call('POST', '/api/webhooks/stripe', [], [], [], [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_Stripe-Signature' => $signature,
        ], $payload);
    }

    private function paymentIntentEvent(string $type, string $intentId): array
    {
        return [
            'id' => 'evt_'.Str::random(16),
            'object' => 'event',
            'type' => $type,
            'data' => [
                'object' => [
                    'id' => $intentId,
                    'object' => 'payment_intent',
                    'status' => $type === 'payment_intent.succeeded' ? 'succeeded' : 'requires_payment_method',
                ],
            ],
        ];
    }

    protected function setUp(): void
    {
        parent::setUp();
        config(['services.stripe.webhook_secret' => self::WEBHOOK_SECRET]);
    }

    public function test_a_request_with_no_signature_is_rejected(): void
    {
        $payload = json_encode(['id' => 'evt_1', 'object' => 'event', 'type' => 'payment_intent.succeeded']);

        $response = $this->call('POST', '/api/webhooks/stripe', [], [], [], [
            'CONTENT_TYPE' => 'application/json',
        ], $payload);

        $response->assertStatus(400);
    }

    public function test_a_tampered_payload_is_rejected(): void
    {
        $event = $this->paymentIntentEvent('payment_intent.succeeded', 'pi_123');
        $payload = json_encode($event);
        $signature = WebhookSignature::generateSignatureHeader($payload, self::WEBHOOK_SECRET);

        // Same signature, different body — this is exactly what signature
        // verification exists to catch.
        $tampered = json_encode([...$event, 'type' => 'payment_intent.payment_failed']);

        $response = $this->call('POST', '/api/webhooks/stripe', [], [], [], [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_Stripe-Signature' => $signature,
        ], $tampered);

        $response->assertStatus(400);
    }

    public function test_payment_intent_succeeded_marks_the_matching_payment_paid(): void
    {
        $lease = LeaseAgreement::factory()->create(['equipment_unit_id' => null]);
        $payment = Payment::factory()->create([
            'lease_agreement_id' => $lease->id,
            'status' => Payment::STATUS_PENDING,
            'stripe_payment_intent_id' => 'pi_abc123',
        ]);

        $response = $this->postWebhook($this->paymentIntentEvent('payment_intent.succeeded', 'pi_abc123'));

        $response->assertOk();
        $this->assertSame(Payment::STATUS_PAID, $payment->fresh()->status);
    }

    public function test_payment_intent_payment_failed_marks_the_matching_payment_failed(): void
    {
        $lease = LeaseAgreement::factory()->create(['equipment_unit_id' => null]);
        $payment = Payment::factory()->create([
            'lease_agreement_id' => $lease->id,
            'status' => Payment::STATUS_PENDING,
            'stripe_payment_intent_id' => 'pi_def456',
        ]);

        $response = $this->postWebhook($this->paymentIntentEvent('payment_intent.payment_failed', 'pi_def456'));

        $response->assertOk();
        $this->assertSame(Payment::STATUS_FAILED, $payment->fresh()->status);
    }

    public function test_an_event_for_an_unknown_payment_intent_is_a_harmless_no_op(): void
    {
        $response = $this->postWebhook($this->paymentIntentEvent('payment_intent.succeeded', 'pi_never_scheduled'));

        $response->assertOk();
    }

    public function test_a_redelivered_event_is_not_applied_twice(): void
    {
        $lease = LeaseAgreement::factory()->create(['equipment_unit_id' => null]);
        $payment = Payment::factory()->create([
            'lease_agreement_id' => $lease->id,
            'status' => Payment::STATUS_PENDING,
            'stripe_payment_intent_id' => 'pi_redelivered',
        ]);

        $event = $this->paymentIntentEvent('payment_intent.succeeded', 'pi_redelivered');

        $this->postWebhook($event)->assertOk();
        $this->assertSame(1, StripeWebhookEvent::where('stripe_event_id', $event['id'])->count());

        // Manually revert the payment the way an admin correction would, then
        // replay the exact same event — if it were re-applied, the payment
        // would flip back to paid. It shouldn't, because the event id was
        // already recorded as processed. refresh() first: the webhook above
        // updated the row through a separate model instance inside the
        // controller, so this test's $payment is still holding the
        // pre-webhook ('pending') attributes in memory — without refreshing,
        // Eloquent's dirty-checking would see the revert below as a no-op.
        $payment->refresh()->update(['status' => Payment::STATUS_PENDING]);
        $this->postWebhook($event)->assertOk();

        $this->assertSame(Payment::STATUS_PENDING, $payment->fresh()->status);
        $this->assertSame(1, StripeWebhookEvent::where('stripe_event_id', $event['id'])->count());
    }
}
