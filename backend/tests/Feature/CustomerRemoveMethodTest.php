<?php

namespace Tests\Feature;

use App\Models\LeaseAgreement;
use App\Models\Payment;
use App\Models\PaymentAttempt;
use App\Models\PaymentAuditLog;
use App\Models\PaymentPart;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Stripe\ApiRequestor;
use Tests\Support\FakeStripeHttpClient;
use Tests\TestCase;

/**
 * A customer removes a saved bank account or card (client, Joel, 2026-10-19 checklist, section 2: "remove payment method"). It also comes
 * off their Stripe profile. It is refused, in words for the customer, when removing it would break something.
 */
class CustomerRemoveMethodTest extends TestCase
{
    use RefreshDatabase;

    private FakeStripeHttpClient $stripe;

    private LeaseAgreement $lease;

    protected function setUp(): void
    {
        parent::setUp();
        $this->stripe = new FakeStripeHttpClient;
        ApiRequestor::setHttpClient($this->stripe);

        $customer = User::factory()->create(['role' => User::ROLE_CUSTOMER, 'name' => 'Jane Doe']);
        $customer->customerProfile()->create(['stripe_customer_id' => 'cus_remove']);
        $this->lease = LeaseAgreement::factory()->pickedUp()->create([
            'customer_id' => $customer->id, 'autopay_enabled' => false,
            'stripe_bank_payment_method_id' => 'pm_bank', 'stripe_card_payment_method_id' => 'pm_card', 'autopay_primary_method' => 'ach',
        ]);
    }

    protected function tearDown(): void
    {
        ApiRequestor::setHttpClient(null);
        parent::tearDown();
    }

    private function remove(string $type, array $extra = [], ?User $as = null): TestResponse
    {
        return $this->actingAs($as ?? $this->lease->customer, 'sanctum')
            ->postJson("/api/customer/lease-agreements/{$this->lease->id}/payment-methods/remove", array_merge(['type' => $type, 'confirm' => true], $extra));
    }

    private function paths(): array
    {
        return array_map(fn ($r) => parse_url($r['url'], PHP_URL_PATH), $this->stripe->requests);
    }

    private function due(): Payment
    {
        return Payment::factory()->create(['lease_agreement_id' => $this->lease->id, 'type' => Payment::TYPE_RENTAL, 'amount' => 200, 'due_date' => '2026-10-10', 'status' => Payment::STATUS_PENDING]);
    }

    public function test_a_card_is_removed_from_the_lease_and_from_stripe(): void
    {
        $this->remove('card')->assertOk()->assertJsonPath('data.card_added', false)->assertJsonPath('data.bank_account_added', true);

        $lease = $this->lease->fresh();
        $this->assertNull($lease->stripe_card_payment_method_id);
        $this->assertSame('pm_bank', $lease->stripe_bank_payment_method_id);
        $this->assertSame('ach', $lease->autopay_primary_method, 'The one that was chosen first is untouched.');
        $this->assertContains('/v1/payment_methods/pm_card/detach', $this->paths());
        $audit = PaymentAuditLog::where('action', 'method.removed')->firstOrFail();
        $this->assertSame('customer', $audit->actor_type);
        $this->assertSame('card', $audit->method);
    }

    public function test_removing_the_method_that_was_chosen_first_hands_that_to_the_one_left(): void
    {
        $this->lease->update(['autopay_enabled' => true]);

        $this->remove('bank')->assertOk()->assertJsonPath('data.autopay_primary_method', 'card');

        $this->assertNull($this->lease->fresh()->stripe_bank_payment_method_id);
        $this->assertContains('/v1/payment_methods/pm_bank/detach', $this->paths());
    }

    public function test_the_only_method_cannot_be_removed_while_autopay_is_on_but_can_once_it_is_off(): void
    {
        $this->lease->update(['autopay_enabled' => true, 'stripe_card_payment_method_id' => null]);

        $this->remove('bank')->assertStatus(422)->assertJsonPath('message', 'AutoPay is on and this bank account is the only payment method you have saved. Add another one first, or turn AutoPay off.');
        $this->assertSame('pm_bank', $this->lease->fresh()->stripe_bank_payment_method_id);
        $this->assertCount(0, $this->stripe->requests);

        $this->lease->update(['autopay_enabled' => false]);
        $this->remove('bank')->assertOk()->assertJsonPath('data.bank_account_added', false)->assertJsonPath('data.autopay_primary_method', null);
    }

    public function test_a_locked_method_is_left_to_staff(): void
    {
        $this->lease->update(['card_locked_at' => now(), 'card_lock_reason' => 'Fraud']);

        $this->remove('card')->assertStatus(422)->assertJsonPath('message', 'This card cannot be removed here. Please contact us.');

        $this->assertSame('pm_card', $this->lease->fresh()->stripe_card_payment_method_id);
        // The other one is not locked.
        $this->remove('bank')->assertOk();
    }

    public function test_a_method_with_a_payment_still_on_its_way_stays_until_it_clears(): void
    {
        $payment = $this->due();
        PaymentAttempt::create([
            'payment_id' => $payment->id, 'attempt_no' => 1, 'round' => 1, 'method' => 'bank', 'amount_cents' => 20000, 'fee_cents' => 0,
            'stripe_payment_method_id' => 'pm_bank', 'idempotency_key' => 'attempt-1', 'stripe_payment_intent_id' => 'pi_1', 'status' => PaymentAttempt::STATUS_PROCESSING,
        ]);

        $this->remove('bank')->assertStatus(422)->assertJsonPath('message', 'A payment is still being processed with this bank account. You can remove it once that payment clears.');
        $this->assertSame('pm_bank', $this->lease->fresh()->stripe_bank_payment_method_id);
        // A debit on the bank account does not hold back the card.
        $this->remove('card')->assertOk();

        PaymentAttempt::query()->update(['status' => PaymentAttempt::STATUS_SUCCEEDED]);
        $this->remove('bank')->assertOk();
    }

    public function test_a_piece_of_a_split_payment_on_its_way_also_holds_the_method_back(): void
    {
        $payment = $this->due();
        $payment->parts()->create(['method' => 'card', 'amount' => 50, 'card_fee_amount' => 1.5, 'status' => PaymentPart::STATUS_PENDING, 'stripe_payment_intent_id' => 'pi_part', 'started_by' => PaymentPart::BY_CUSTOMER]);

        $this->remove('card')->assertStatus(422)->assertJsonPath('message', 'A payment is still being processed with this card. You can remove it once that payment clears.');
    }

    public function test_it_needs_a_saved_method_a_listed_type_and_a_confirmation(): void
    {
        $this->lease->update(['stripe_card_payment_method_id' => null]);

        $this->remove('card')->assertStatus(422)->assertJsonPath('message', 'There is no card saved to remove.');
        $this->remove('cash')->assertStatus(422)->assertJsonValidationErrors('type');
        $this->actingAs($this->lease->customer, 'sanctum')
            ->postJson("/api/customer/lease-agreements/{$this->lease->id}/payment-methods/remove", ['type' => 'bank'])
            ->assertStatus(422)
            ->assertJsonValidationErrors('confirm');
        $this->assertSame('pm_bank', $this->lease->fresh()->stripe_bank_payment_method_id);
    }

    public function test_only_the_customer_of_the_lease_can_remove_its_method(): void
    {
        $other = User::factory()->create(['role' => User::ROLE_CUSTOMER]);
        $admin = User::factory()->create(['role' => User::ROLE_SUPER_ADMIN]);

        $this->remove('card', [], $other)->assertNotFound();
        $this->remove('card', [], $admin)->assertForbidden();

        $this->assertSame('pm_card', $this->lease->fresh()->stripe_card_payment_method_id);
    }
}
