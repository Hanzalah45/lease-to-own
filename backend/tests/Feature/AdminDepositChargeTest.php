<?php

namespace Tests\Feature;

use App\Models\AdminPermission;
use App\Models\Application;
use App\Models\Contract;
use App\Models\LeaseAgreement;
use App\Models\Payment;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Stripe\ApiRequestor;
use Tests\Support\FakeStripeHttpClient;
use Tests\TestCase;

/**
 * Staff charge the security deposit to the customer's saved method after the
 * customer agreed (client, Joel, 2026-10-12). Real case: a customer took the
 * equipment with the deposit marked received but never collected, and the
 * customer's own deposit button was already closed.
 */
class AdminDepositChargeTest extends TestCase
{
    use RefreshDatabase;

    private FakeStripeHttpClient $stripe;

    protected function setUp(): void
    {
        parent::setUp();
        $this->stripe = new FakeStripeHttpClient;
        ApiRequestor::setHttpClient($this->stripe);
    }

    protected function tearDown(): void
    {
        ApiRequestor::setHttpClient(null);
        parent::tearDown();
    }

    /** A picked-up lease whose deposit was marked received by hand but never paid. */
    private function leaseWithOverlookedDeposit(array $applicationOverrides = []): LeaseAgreement
    {
        $customer = User::factory()->create(['role' => User::ROLE_CUSTOMER]);
        $customer->customerProfile()->create(['stripe_customer_id' => 'cus_deposit']);
        $application = Application::factory()->create(array_merge([
            'customer_id' => $customer->id,
            'status' => Application::STATUS_FINISHED,
            'deposit_received' => true,
        ], $applicationOverrides));
        $lease = LeaseAgreement::factory()->create([
            'application_id' => $application->id,
            'customer_id' => $customer->id,
            'equipment_unit_id' => null,
            'security_deposit' => 342.93,
            'monthly_rental_payment' => 200,
            'sales_tax_rate' => 0,
            'ldw_selected' => false,
            'stripe_card_payment_method_id' => 'pm_card_saved',
            'stripe_bank_payment_method_id' => 'pm_bank_saved',
            'autopay_primary_method' => 'ach',
        ]);
        Contract::factory()->create(['lease_agreement_id' => $lease->id, 'signer_user_id' => $customer->id]);

        return $lease;
    }

    private function admin(): User
    {
        return User::factory()->create(['role' => User::ROLE_SUPER_ADMIN]);
    }

    private function charge(User $admin, LeaseAgreement $lease, array $body = [])
    {
        return $this->actingAs($admin, 'sanctum')->postJson("/api/admin/applications/{$lease->application_id}/charge-deposit", array_merge([
            'method' => 'card',
            'expected_total_cents' => 35322, // $342.93 + 3% card fee ($10.29)
            'customer_agreed' => true,
        ], $body));
    }

    public function test_an_admin_charges_the_saved_card_for_the_deposit(): void
    {
        $lease = $this->leaseWithOverlookedDeposit();
        $admin = $this->admin();
        $this->stripe->queue(['id' => 'pi_deposit1', 'object' => 'payment_intent', 'status' => 'succeeded']);

        $this->charge($admin, $lease)->assertOk()->assertJsonPath('data.payment.status', 'paid');

        $payment = Payment::where('lease_agreement_id', $lease->id)->where('type', Payment::TYPE_DEPOSIT)->first();
        $this->assertSame(Payment::STATUS_PAID, $payment->status);
        $this->assertSame('card', $payment->method);
        $this->assertEqualsWithDelta(342.93, (float) $payment->amount, 0.001);
        $this->assertEqualsWithDelta(10.29, (float) $payment->card_fee_amount, 0.001);
        $this->assertSame($admin->id, $payment->recorded_by);
        $this->assertSame('pi_deposit1', $payment->stripe_payment_intent_id);

        // One charge, for the card price, made off-session on the saved card.
        $this->assertCount(1, $this->stripe->requests);
        $params = $this->stripe->requests[0]['params'];
        $this->assertSame(35322, (int) $params['amount']);
        $this->assertSame('pm_card_saved', $params['payment_method']);
        $this->assertTrue((bool) $params['off_session']);
        $this->assertSame((string) $admin->id, (string) $params['metadata']['charged_by_staff_id']);

        $note = $lease->application->dealerNotes()->first();
        $this->assertSame($admin->id, $note->author_user_id);
        $this->assertStringContainsString('$353.22', $note->text);
        $this->assertStringContainsString($admin->name, $note->text);
    }

    public function test_the_customer_must_be_confirmed_as_agreeing(): void
    {
        $lease = $this->leaseWithOverlookedDeposit();

        $this->charge($this->admin(), $lease, ['customer_agreed' => false])->assertStatus(422);
        $this->charge($this->admin(), $lease, ['customer_agreed' => null])->assertStatus(422);

        $this->assertCount(0, $this->stripe->requests);
        $this->assertSame(0, Payment::count());
    }

    public function test_a_changed_price_is_refused_without_charging(): void
    {
        $lease = $this->leaseWithOverlookedDeposit();

        $this->charge($this->admin(), $lease, ['expected_total_cents' => 34293])->assertStatus(409);

        $this->assertCount(0, $this->stripe->requests);
        $this->assertSame(0, Payment::count());
    }

    public function test_a_deposit_that_is_already_paid_is_not_charged_again(): void
    {
        $lease = $this->leaseWithOverlookedDeposit();
        Payment::factory()->create([
            'lease_agreement_id' => $lease->id,
            'type' => Payment::TYPE_DEPOSIT,
            'amount' => 342.93,
            'status' => Payment::STATUS_PAID,
        ]);

        $this->charge($this->admin(), $lease)->assertStatus(422);

        $this->assertCount(0, $this->stripe->requests);
    }

    public function test_an_unsigned_lease_is_refused(): void
    {
        $lease = $this->leaseWithOverlookedDeposit();
        $lease->contract()->delete();

        $this->charge($this->admin(), $lease)->assertStatus(422);

        $this->assertCount(0, $this->stripe->requests);
    }

    public function test_a_declined_card_collects_nothing_and_is_noted(): void
    {
        $lease = $this->leaseWithOverlookedDeposit();
        $this->stripe->queue([
            'error' => [
                'message' => 'Your card was declined.',
                'type' => 'card_error',
                'code' => 'card_declined',
                'decline_code' => 'generic_decline',
                'payment_intent' => ['id' => 'pi_declined', 'object' => 'payment_intent', 'status' => 'requires_payment_method'],
            ],
        ], 402);

        $this->charge($this->admin(), $lease)->assertStatus(422);

        $payment = Payment::where('lease_agreement_id', $lease->id)->where('type', Payment::TYPE_DEPOSIT)->first();
        $this->assertSame(Payment::STATUS_FAILED, $payment->status);
        $this->assertStringContainsString('declined', $lease->application->dealerNotes()->first()->text);

        // A failed attempt can be tried again (it is not "already charged").
        $this->stripe->queue(['id' => 'pi_retry', 'object' => 'payment_intent', 'status' => 'succeeded']);
        $this->charge($this->admin(), $lease)->assertOk();
        $this->assertSame(1, Payment::where('type', Payment::TYPE_DEPOSIT)->where('status', Payment::STATUS_PAID)->count());
    }

    public function test_a_bank_debit_is_left_processing_at_the_bank_price(): void
    {
        $lease = $this->leaseWithOverlookedDeposit();
        $this->stripe->queue(['id' => 'pi_bank1', 'object' => 'payment_intent', 'status' => 'processing']);

        $this->charge($this->admin(), $lease, ['method' => 'bank', 'expected_total_cents' => 34293])
            ->assertOk()
            ->assertJsonPath('data.payment.status', 'pending');

        $payment = Payment::where('type', Payment::TYPE_DEPOSIT)->first();
        $this->assertSame('ach', $payment->method);
        $this->assertEqualsWithDelta(0.0, (float) $payment->card_fee_amount, 0.001);
    }

    public function test_only_staff_with_payment_tracking_can_charge(): void
    {
        $lease = $this->leaseWithOverlookedDeposit();

        $this->charge($lease->customer, $lease)->assertForbidden();

        $reviewer = User::factory()->create(['role' => User::ROLE_ADMIN]);
        AdminPermission::create(['user_id' => $reviewer->id, 'permission' => AdminPermission::APPLICATION_REVIEW]);
        $this->charge($reviewer, $lease)->assertForbidden();

        $this->assertCount(0, $this->stripe->requests);
    }
}
