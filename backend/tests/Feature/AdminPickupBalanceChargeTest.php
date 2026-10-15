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
 * Staff charge the tracking fee and first month to the customer's saved method after
 * the customer agreed (client, Joel, 2026-10-16). Real case: a customer took the mower
 * having paid only the deposit, and the first month had no way to be collected.
 */
class AdminPickupBalanceChargeTest extends TestCase
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

    /** A signed lease whose deposit is still being debited and whose tracking fee and first month nobody has collected. */
    private function lease(string $status = Application::STATUS_WAITING_DEPOSIT): LeaseAgreement
    {
        $customer = User::factory()->create(['role' => User::ROLE_CUSTOMER]);
        $customer->customerProfile()->create(['stripe_customer_id' => 'cus_balance']);
        $application = Application::factory()->create(['customer_id' => $customer->id, 'status' => $status]);
        $lease = LeaseAgreement::factory()->create([
            'application_id' => $application->id,
            'customer_id' => $customer->id,
            'equipment_unit_id' => null,
            'security_deposit' => 300,
            'monthly_rental_payment' => 200,
            'sales_tax_rate' => 0,
            'ldw_selected' => false,
            'stripe_card_payment_method_id' => 'pm_card_saved',
            'stripe_bank_payment_method_id' => 'pm_bank_saved',
            'autopay_primary_method' => 'ach',
        ]);
        Contract::factory()->create(['lease_agreement_id' => $lease->id, 'signer_user_id' => $customer->id]);
        Payment::factory()->create([
            'lease_agreement_id' => $lease->id,
            'type' => Payment::TYPE_DEPOSIT,
            'status' => Payment::STATUS_PENDING,
            'amount' => 300,
            'stripe_payment_intent_id' => 'pi_deposit_'.$lease->id,
        ]);

        return $lease;
    }

    private function admin(): User
    {
        return User::factory()->create(['role' => User::ROLE_SUPER_ADMIN]);
    }

    private function charge(User $admin, LeaseAgreement $lease, array $body = [])
    {
        return $this->actingAs($admin, 'sanctum')->postJson("/api/admin/applications/{$lease->application_id}/charge-pickup-balance", array_merge([
            'method' => 'bank',
            'expected_total_cents' => 35000, // $150 tracking fee + $200 first month
            'customer_agreed' => true,
        ], $body));
    }

    private function balanceRow(LeaseAgreement $lease): ?Payment
    {
        return Payment::where('lease_agreement_id', $lease->id)->where('type', Payment::TYPE_PICKUP_BALANCE)->latest('id')->first();
    }

    public function test_an_admin_charges_the_saved_bank_account_while_the_deposit_is_still_processing(): void
    {
        $lease = $this->lease();
        $admin = $this->admin();
        $this->stripe->queue(['id' => 'pi_balance1', 'object' => 'payment_intent', 'status' => 'processing']);

        $this->charge($admin, $lease)->assertOk()->assertJsonPath('data.payment.status', 'pending');

        $payment = $this->balanceRow($lease);
        $this->assertSame('ach', $payment->method);
        $this->assertEqualsWithDelta(350.0, (float) $payment->amount, 0.001);
        $this->assertEqualsWithDelta(0.0, (float) $payment->card_fee_amount, 0.001);
        $this->assertSame($admin->id, $payment->recorded_by);
        $this->assertSame('pi_balance1', $payment->stripe_payment_intent_id);

        // One charge at the bank price, made off-session on the saved bank account.
        $this->assertCount(1, $this->stripe->requests);
        $params = $this->stripe->requests[0]['params'];
        $this->assertSame(35000, (int) $params['amount']);
        $this->assertSame('pm_bank_saved', $params['payment_method']);
        $this->assertTrue((bool) $params['off_session']);
        $this->assertStringContainsString('Tracking fee', $params['description']);

        $note = $lease->application->dealerNotes()->latest('id')->first();
        $this->assertSame($admin->id, $note->author_user_id);
        $this->assertStringContainsString('Tracking fee and first month', $note->text);
        $this->assertStringContainsString('$350.00', $note->text);
        $this->assertStringContainsString($admin->name, $note->text);
    }

    public function test_the_card_is_charged_at_the_card_price(): void
    {
        $lease = $this->lease(Application::STATUS_WAITING_DELIVERY);
        $this->stripe->queue(['id' => 'pi_balance_card', 'object' => 'payment_intent', 'status' => 'succeeded']);

        $this->charge($this->admin(), $lease, ['method' => 'card', 'expected_total_cents' => 36050])
            ->assertOk()
            ->assertJsonPath('data.payment.status', 'paid');

        $payment = $this->balanceRow($lease);
        $this->assertSame('card', $payment->method);
        $this->assertEqualsWithDelta(10.5, (float) $payment->card_fee_amount, 0.001);
        $this->assertSame(36050, (int) $this->stripe->requests[0]['params']['amount']);
        // The deposit is a different charge and is left alone.
        $this->assertSame(Payment::STATUS_PENDING, Payment::where('type', Payment::TYPE_DEPOSIT)->first()->status);
    }

    public function test_the_customer_must_be_confirmed_as_agreeing(): void
    {
        $lease = $this->lease();

        $this->charge($this->admin(), $lease, ['customer_agreed' => false])->assertStatus(422);

        $this->assertCount(0, $this->stripe->requests);
        $this->assertNull($this->balanceRow($lease));
    }

    public function test_a_changed_price_is_refused_without_charging(): void
    {
        $lease = $this->lease();

        $this->charge($this->admin(), $lease, ['expected_total_cents' => 34000])->assertStatus(409);

        $this->assertCount(0, $this->stripe->requests);
        $this->assertNull($this->balanceRow($lease));
    }

    public function test_a_balance_that_is_paid_or_already_processing_is_not_charged_again(): void
    {
        foreach ([Payment::STATUS_PAID, Payment::STATUS_PENDING] as $status) {
            $lease = $this->lease();
            Payment::factory()->create([
                'lease_agreement_id' => $lease->id,
                'type' => Payment::TYPE_PICKUP_BALANCE,
                'status' => $status,
                'amount' => 350,
                'stripe_payment_intent_id' => 'pi_earlier_'.$lease->id,
            ]);

            $this->charge($this->admin(), $lease)->assertStatus(422);
        }

        $this->assertCount(0, $this->stripe->requests);
    }

    public function test_a_declined_card_collects_nothing_and_is_noted(): void
    {
        $lease = $this->lease();
        $this->stripe->queue([
            'error' => [
                'message' => 'Your card has insufficient funds.',
                'type' => 'card_error',
                'code' => 'card_declined',
                'decline_code' => 'insufficient_funds',
                'payment_intent' => ['id' => 'pi_declined_balance', 'object' => 'payment_intent', 'status' => 'requires_payment_method'],
            ],
        ], 402);

        $this->charge($this->admin(), $lease, ['method' => 'card', 'expected_total_cents' => 36050])->assertStatus(422);

        $payment = $this->balanceRow($lease);
        $this->assertSame(Payment::STATUS_FAILED, $payment->status);
        $this->assertSame('Your card has insufficient funds.', $payment->failure_message);
        $this->assertStringContainsString('declined', $lease->application->dealerNotes()->latest('id')->first()->text);
    }

    public function test_an_unsigned_lease_or_an_early_stage_is_refused(): void
    {
        $unsigned = $this->lease();
        $unsigned->contract()->delete();
        $this->charge($this->admin(), $unsigned)->assertStatus(422);

        $early = $this->lease(Application::STATUS_IN_VERIFICATION);
        $this->charge($this->admin(), $early)->assertStatus(422);

        $this->assertCount(0, $this->stripe->requests);
    }

    public function test_only_staff_with_payment_tracking_can_charge(): void
    {
        $lease = $this->lease();

        $this->charge($lease->customer, $lease)->assertForbidden();

        $reviewer = User::factory()->create(['role' => User::ROLE_ADMIN]);
        AdminPermission::create(['user_id' => $reviewer->id, 'permission' => AdminPermission::APPLICATION_REVIEW]);
        $this->charge($reviewer, $lease)->assertForbidden();

        $this->assertCount(0, $this->stripe->requests);
    }
}
