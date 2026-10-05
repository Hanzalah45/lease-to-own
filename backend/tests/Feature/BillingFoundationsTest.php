<?php

namespace Tests\Feature;

use App\Models\Application;
use App\Models\Contract;
use App\Models\LeaseAgreement;
use App\Models\Payment;
use App\Models\RiskRedFlag;
use App\Models\StripeWebhookEvent;
use App\Models\User;
use App\Services\LeaseEngine;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Stripe\ApiRequestor;
use Stripe\WebhookSignature;
use Tests\Support\FakeStripeHttpClient;
use Tests\TestCase;

/**
 * Safety fixes that the billing-cycle / AutoPay-charging work (2026-10-05)
 * depends on: schedule helpers that only ever touch rental rows, the missed
 * payment flagger ignoring in-flight charges and not-yet-picked-up leases,
 * EPO arrears ignoring in-flight ACH debits, a hardened webhook, and an
 * idempotency key on customer-initiated charges.
 */
class BillingFoundationsTest extends TestCase
{
    use RefreshDatabase;

    private FakeStripeHttpClient $stripe;

    protected function setUp(): void
    {
        parent::setUp();
        $this->stripe = new FakeStripeHttpClient;
        ApiRequestor::setHttpClient($this->stripe);
        $this->travelTo(Carbon::parse('2026-10-05 18:00:00', 'UTC'));
    }

    protected function tearDown(): void
    {
        ApiRequestor::setHttpClient(null);
        parent::tearDown();
    }

    private function lease(array $overrides = [], bool $pickedUp = false): LeaseAgreement
    {
        $factory = $pickedUp ? LeaseAgreement::factory()->pickedUp() : LeaseAgreement::factory();

        return $factory->create(array_merge([
            'term_months' => 12,
            'start_date' => '2026-10-01',
            'monthly_rental_payment' => 150,
            'sales_tax_rate' => 0,
            'ldw_selected' => false,
            'cash_price' => 3000,
        ], $overrides));
    }

    // ---- schedule helpers only ever touch rental rows ----

    public function test_a_paid_deposit_does_not_block_generating_the_monthly_schedule(): void
    {
        $lease = $this->lease();
        Payment::factory()->create(['lease_agreement_id' => $lease->id, 'type' => Payment::TYPE_DEPOSIT, 'status' => Payment::STATUS_PAID]);

        LeaseEngine::generatePaymentSchedule($lease);

        $this->assertSame(12, $lease->payments()->where('type', Payment::TYPE_RENTAL)->count());
    }

    public function test_regenerating_the_schedule_never_deletes_other_payment_types(): void
    {
        $lease = $this->lease();
        LeaseEngine::generatePaymentSchedule($lease);
        $deposit = Payment::factory()->create(['lease_agreement_id' => $lease->id, 'type' => Payment::TYPE_DEPOSIT, 'status' => Payment::STATUS_PAID]);
        $balance = Payment::factory()->create(['lease_agreement_id' => $lease->id, 'type' => Payment::TYPE_PICKUP_BALANCE, 'status' => Payment::STATUS_PENDING]);

        $lease->update(['term_months' => 24]);
        LeaseEngine::regeneratePaymentSchedule($lease->fresh());

        $this->assertSame(24, $lease->payments()->where('type', Payment::TYPE_RENTAL)->count());
        $this->assertNotNull($deposit->fresh());
        $this->assertNotNull($balance->fresh());
    }

    public function test_regenerating_is_refused_once_a_rental_payment_was_made(): void
    {
        $lease = $this->lease();
        LeaseEngine::generatePaymentSchedule($lease);
        $lease->payments()->where('type', Payment::TYPE_RENTAL)->orderBy('due_date')->first()->update(['status' => Payment::STATUS_PAID]);

        $this->expectException(\RuntimeException::class);
        LeaseEngine::regeneratePaymentSchedule($lease->fresh());
    }

    public function test_mark_first_payment_paid_never_picks_a_pickup_balance_row(): void
    {
        $lease = $this->lease();
        $balance = Payment::factory()->create([
            'lease_agreement_id' => $lease->id,
            'type' => Payment::TYPE_PICKUP_BALANCE,
            'status' => Payment::STATUS_PENDING,
            'due_date' => '2026-01-01',
        ]);
        LeaseEngine::generatePaymentSchedule($lease);

        $paid = LeaseEngine::markFirstPaymentPaid($lease->fresh(), User::factory()->create()->id);

        $this->assertSame(Payment::TYPE_RENTAL, $paid->type);
        $this->assertSame(Payment::STATUS_PENDING, $balance->fresh()->status);
    }

    // ---- missed-payment flagger ----

    private function overdueRow(LeaseAgreement $lease, array $overrides = []): Payment
    {
        return Payment::factory()->create(array_merge([
            'lease_agreement_id' => $lease->id,
            'status' => Payment::STATUS_PENDING,
            'due_date' => '2026-10-01',
        ], $overrides));
    }

    public function test_an_overdue_payment_on_a_picked_up_lease_is_flagged(): void
    {
        $lease = $this->lease(pickedUp: true);
        $this->overdueRow($lease);

        $this->artisan('risk:flag-missed-payments')->assertSuccessful();

        $this->assertSame(1, RiskRedFlag::where('type', RiskRedFlag::TYPE_MISSED_PAYMENT)->count());
    }

    public function test_an_overdue_payment_before_pickup_is_not_flagged(): void
    {
        $lease = $this->lease();
        $this->overdueRow($lease);

        $this->artisan('risk:flag-missed-payments')->assertSuccessful();

        $this->assertSame(0, RiskRedFlag::where('type', RiskRedFlag::TYPE_MISSED_PAYMENT)->count());
    }

    public function test_a_payment_with_an_in_flight_ach_charge_is_not_flagged(): void
    {
        $lease = $this->lease(pickedUp: true);
        $this->overdueRow($lease, ['stripe_payment_intent_id' => 'pi_processing']);

        $this->artisan('risk:flag-missed-payments')->assertSuccessful();

        $this->assertSame(0, RiskRedFlag::where('type', RiskRedFlag::TYPE_MISSED_PAYMENT)->count());
    }

    public function test_a_payment_due_today_is_not_flagged_yet(): void
    {
        $lease = $this->lease(pickedUp: true);
        $this->overdueRow($lease, ['due_date' => '2026-10-05']);

        $this->artisan('risk:flag-missed-payments')->assertSuccessful();

        $this->assertSame(0, RiskRedFlag::where('type', RiskRedFlag::TYPE_MISSED_PAYMENT)->count());
    }

    // ---- EPO arrears ----

    public function test_an_in_flight_ach_row_does_not_inflate_the_epo_price(): void
    {
        $lease = $this->lease(['term_months' => 36]);
        foreach (['2026-06-01', '2026-07-01', '2026-08-01', '2026-09-01'] as $due) {
            Payment::factory()->create(['lease_agreement_id' => $lease->id, 'due_date' => $due, 'status' => Payment::STATUS_PAID, 'amount' => 150]);
        }
        $baseline = LeaseEngine::epoToday($lease->fresh());

        $this->overdueRow($lease, ['amount' => 150, 'stripe_payment_intent_id' => 'pi_processing']);

        $this->assertSame($baseline, LeaseEngine::epoToday($lease->fresh()));
    }

    public function test_a_failed_or_unattempted_overdue_row_still_counts_as_arrears(): void
    {
        $lease = $this->lease(['term_months' => 36]);
        foreach (['2026-06-01', '2026-07-01', '2026-08-01', '2026-09-01'] as $due) {
            Payment::factory()->create(['lease_agreement_id' => $lease->id, 'due_date' => $due, 'status' => Payment::STATUS_PAID, 'amount' => 150]);
        }
        $baseline = LeaseEngine::epoToday($lease->fresh());

        $this->overdueRow($lease, ['amount' => 150, 'status' => Payment::STATUS_FAILED, 'stripe_payment_intent_id' => 'pi_failed']);

        $this->assertSame($baseline + 150.0, LeaseEngine::epoToday($lease->fresh()));
    }

    // ---- webhook ----

    private function webhook(string $type, string $intentId, string $status): TestResponse
    {
        config(['services.stripe.webhook_secret' => 'whsec_test_secret_for_specs_only']);
        $payload = json_encode([
            'id' => 'evt_'.Str::random(16),
            'object' => 'event',
            'type' => $type,
            'data' => ['object' => ['id' => $intentId, 'object' => 'payment_intent', 'status' => $status]],
        ]);

        return $this->call('POST', '/api/webhooks/stripe', [], [], [], [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_Stripe-Signature' => WebhookSignature::generateSignatureHeader($payload, 'whsec_test_secret_for_specs_only'),
        ], $payload);
    }

    public function test_a_late_failed_event_never_moves_a_paid_payment_back_to_failed(): void
    {
        $lease = $this->lease(pickedUp: true);
        $payment = $this->overdueRow($lease, ['status' => Payment::STATUS_PAID, 'stripe_payment_intent_id' => 'pi_done']);

        $this->webhook('payment_intent.payment_failed', 'pi_done', 'requires_payment_method')->assertOk();

        $this->assertSame(Payment::STATUS_PAID, $payment->fresh()->status);
        $this->assertSame(0, RiskRedFlag::where('type', RiskRedFlag::TYPE_FAILED_ACH)->count());
    }

    public function test_a_processing_event_changes_nothing(): void
    {
        $lease = $this->lease(pickedUp: true);
        $payment = $this->overdueRow($lease, ['stripe_payment_intent_id' => 'pi_proc']);

        $this->webhook('payment_intent.processing', 'pi_proc', 'processing')->assertOk();

        $this->assertSame(Payment::STATUS_PENDING, $payment->fresh()->status);
    }

    public function test_an_event_whose_processing_throws_is_released_so_stripe_can_retry_it(): void
    {
        $lease = $this->lease(pickedUp: true);
        $this->overdueRow($lease, ['stripe_payment_intent_id' => 'pi_retry']);

        // Force the apply step to blow up by making the payment's lease vanish mid-flight.
        Event::listen('eloquent.updating: '.Payment::class, fn () => throw new \RuntimeException('boom'));

        $this->withoutExceptionHandling();
        try {
            $this->webhook('payment_intent.succeeded', 'pi_retry', 'succeeded');
            $this->fail('Expected the webhook to throw.');
        } catch (\RuntimeException) {
            // expected
        }

        $this->assertSame(0, StripeWebhookEvent::count());
    }

    // ---- customer-initiated charge: idempotency + metadata ----

    public function test_a_customer_deposit_charge_sends_an_idempotency_key_and_metadata(): void
    {
        $customer = User::factory()->create(['role' => User::ROLE_CUSTOMER]);
        $application = Application::factory()->create(['customer_id' => $customer->id, 'status' => Application::STATUS_WAITING_DEPOSIT]);
        $lease = LeaseAgreement::factory()->create([
            'application_id' => $application->id,
            'customer_id' => $customer->id,
            'equipment_unit_id' => null,
            'security_deposit' => 300,
            'stripe_card_payment_method_id' => 'pm_card_existing',
            'autopay_primary_method' => 'card',
        ]);
        Contract::factory()->create(['lease_agreement_id' => $lease->id, 'signer_user_id' => $customer->id]);

        $this->stripe->queue(['id' => 'cus_idem', 'object' => 'customer']);
        $this->stripe->queue(['id' => 'pi_idem', 'object' => 'payment_intent', 'status' => 'succeeded']);

        $this->actingAs($customer, 'sanctum')
            ->postJson("/api/customer/lease-agreements/{$lease->id}/deposit-payment/charge")->assertOk();

        $intentIndex = collect($this->stripe->requests)->search(fn ($r) => str_contains($r['url'], '/payment_intents'));
        $payment = Payment::where('type', Payment::TYPE_DEPOSIT)->first();

        $this->assertSame("customer-charge-payment-{$payment->id}", $this->stripe->idempotencyKey($intentIndex));
        $this->assertSame((string) $payment->id, (string) $this->stripe->requests[$intentIndex]['params']['metadata']['payment_id']);
    }
}
