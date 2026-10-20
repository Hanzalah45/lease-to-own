<?php

namespace Tests\Feature;

use App\Models\AdditionalFundsEntry;
use App\Models\AdminPermission;
use App\Models\Application;
use App\Models\Contract;
use App\Models\LeaseAgreement;
use App\Models\Payment;
use App\Models\PaymentAuditLog;
use App\Models\PaymentRefund;
use App\Models\User;
use App\Services\AdditionalFundsLedger;
use App\Services\LeaseEngine;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Stripe\ApiRequestor;
use Tests\Support\FakeStripeHttpClient;
use Tests\TestCase;

/**
 * Additional Funds history, applying them to repairs, and giving what is left back (client, Joel, 2026-10-19 checklist, section 3;
 * contract Section 19): the balance is always the sum of a ledger of movements, repairs come before any refund, and a refund never gives
 * back money that was already applied to repairs.
 */
class AdditionalFundsTest extends TestCase
{
    use RefreshDatabase;

    private FakeStripeHttpClient $stripe;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->stripe = new FakeStripeHttpClient;
        ApiRequestor::setHttpClient($this->stripe);
        $this->travelTo(Carbon::parse('2026-10-20 15:00:00', 'UTC'));
        $this->admin = User::factory()->create(['role' => User::ROLE_SUPER_ADMIN, 'name' => 'Stefany Staff']);
    }

    protected function tearDown(): void
    {
        ApiRequestor::setHttpClient(null);
        parent::tearDown();
    }

    /** A picked-up lease with a signed contract on the current wording. */
    private function lease(array $overrides = [], int $termsVersion = Contract::CURRENT_TERMS_VERSION): LeaseAgreement
    {
        $customer = User::factory()->create(['role' => User::ROLE_CUSTOMER, 'name' => 'Jane Doe']);
        $application = Application::factory()->create(['customer_id' => $customer->id, 'status' => Application::STATUS_FINISHED]);
        $lease = LeaseAgreement::factory()->create(array_merge([
            'application_id' => $application->id, 'customer_id' => $customer->id, 'equipment_unit_id' => null,
            'term_months' => 36, 'cash_price' => 3000, 'monthly_rental_payment' => 200, 'sales_tax_rate' => 0,
            'ldw_selected' => false, 'ldw_amount' => 0, 'additional_funds' => 0,
        ], $overrides));
        Contract::factory()->create(['lease_agreement_id' => $lease->id, 'terms_version' => $termsVersion, 'signed_at' => now()]);

        return $lease;
    }

    /** An extra payment that was paid, with its entry in the ledger. */
    private function extra(LeaseAgreement $lease, float $amount, array $extra = []): Payment
    {
        $payment = Payment::factory()->create(array_merge([
            'lease_agreement_id' => $lease->id, 'type' => Payment::TYPE_EXTRA_PAYMENT, 'amount' => $amount, 'method' => 'cash',
            'due_date' => '2026-10-10', 'status' => Payment::STATUS_PENDING,
        ], $extra));

        return LeaseEngine::applyPaymentStatusChange($payment, Payment::STATUS_PAID, $extra['method'] ?? 'cash', $this->admin->id);
    }

    private function end(LeaseAgreement $lease, string $reason = 'returned'): void
    {
        $lease->forceFill(['ended_at' => now(), 'ended_reason' => $reason, 'ended_by' => $this->admin->id])->save();
    }

    private function asAdmin(): static
    {
        return $this->actingAs($this->admin, 'sanctum');
    }

    private function stripeRefund(string $id, int $cents, string $intent): array
    {
        return ['id' => $id, 'object' => 'refund', 'status' => 'succeeded', 'amount' => $cents, 'currency' => 'usd', 'payment_intent' => $intent, 'metadata' => []];
    }

    // ---- the ledger ----

    public function test_every_movement_of_the_balance_is_an_entry_and_the_balance_is_their_sum(): void
    {
        $lease = $this->lease();
        $first = $this->extra($lease, 100);
        $second = $this->extra($lease, 250);

        $this->asAdmin()->postJson("/api/admin/payments/{$second->id}/refunds", ['amount' => 50, 'reason' => 'overpayment'])->assertCreated();

        $entries = $lease->additionalFundsEntries()->get();
        $this->assertSame(['added', 'added', 'refunded'], $entries->pluck('kind')->all());
        $this->assertEqualsWithDelta(100.0, (float) $entries[0]->amount, 0.001);
        $this->assertEqualsWithDelta(350.0, (float) $entries[1]->balance_after, 0.001);
        $this->assertEqualsWithDelta(-50.0, (float) $entries[2]->amount, 0.001);
        $this->assertSame($second->id, $entries[2]->payment_id);
        $this->assertEqualsWithDelta(300.0, (float) $entries[2]->balance_after, 0.001);
        $this->assertEqualsWithDelta(300.0, (float) $lease->fresh()->additional_funds, 0.001);
        $this->assertEqualsWithDelta((float) $lease->fresh()->additional_funds, (float) $entries->sum('amount'), 0.001);
        $this->assertSame($first->id, $entries[0]->payment_id);
    }

    public function test_the_balance_cannot_go_below_zero_and_a_zero_movement_writes_nothing(): void
    {
        $lease = $this->lease();
        $this->extra($lease, 40);

        $entry = AdditionalFundsLedger::apply($lease, AdditionalFundsEntry::KIND_REPAIRS, -100.0, ['description' => 'x']);

        $this->assertEqualsWithDelta(-40.0, (float) $entry->amount, 0.001, 'Cut to what was there.');
        $this->assertEqualsWithDelta(0.0, (float) $lease->fresh()->additional_funds, 0.001);
        $this->assertNull(AdditionalFundsLedger::apply($lease, AdditionalFundsEntry::KIND_REPAIRS, -5.0));
        $this->assertSame(2, $lease->additionalFundsEntries()->count());
    }

    public function test_the_history_endpoint_lists_newest_first_with_who_and_what(): void
    {
        $lease = $this->lease();
        $this->extra($lease, 100);
        $this->end($lease);
        $this->asAdmin()->postJson("/api/admin/lease-agreements/{$lease->id}/additional-funds/repairs", ['amount' => 30, 'description' => 'New blade and belt'])->assertOk();

        $response = $this->asAdmin()->getJson("/api/admin/lease-agreements/{$lease->id}/additional-funds")->assertOk();

        $this->assertEqualsWithDelta(70.0, $response->json('data.balance'), 0.001);
        $this->assertSame(['repairs', 'added'], array_column($response->json('data.entries'), 'kind'));
        $this->assertSame('Stefany Staff', $response->json('data.entries.0.created_by.name'));
        $this->assertStringContainsString('New blade and belt', $response->json('data.entries.0.description'));
    }

    public function test_a_payment_shows_what_it_is_applied_to(): void
    {
        $lease = $this->lease();
        $extra = $this->extra($lease, 100);
        $rent = Payment::factory()->create(['lease_agreement_id' => $lease->id, 'type' => Payment::TYPE_RENTAL, 'amount' => 200, 'due_date' => '2026-10-01', 'status' => Payment::STATUS_PAID, 'paid_date' => '2026-10-01']);
        $fee = Payment::factory()->create(['lease_agreement_id' => $lease->id, 'type' => Payment::TYPE_LATE_FEE, 'amount' => 20, 'due_date' => '2026-10-01', 'status' => Payment::STATUS_PENDING]);

        $this->assertSame('epo', $this->asAdmin()->getJson("/api/admin/payments/{$extra->id}")->json('data.applied_to'));
        $this->assertSame('rent', $this->asAdmin()->getJson("/api/admin/payments/{$rent->id}")->json('data.applied_to'));
        $this->assertSame('fees', $this->asAdmin()->getJson("/api/admin/payments/{$fee->id}")->json('data.applied_to'));
    }

    // ---- applying to repairs ----

    public function test_funds_are_applied_to_repairs_after_the_equipment_came_back(): void
    {
        $lease = $this->lease();
        $this->extra($lease, 100);
        $this->end($lease);

        $this->asAdmin()->postJson("/api/admin/lease-agreements/{$lease->id}/additional-funds/repairs", ['amount' => 35.50, 'description' => 'Replaced the deck belt'])
            ->assertOk()
            ->assertJsonPath('data.balance', 64.5);

        $entry = $lease->additionalFundsEntries()->reorder()->latest('id')->firstOrFail();
        $this->assertSame('repairs', $entry->kind);
        $this->assertEqualsWithDelta(-35.50, (float) $entry->amount, 0.001);
        $this->assertSame($this->admin->id, $entry->created_by);
        $this->assertStringContainsString('Replaced the deck belt', $lease->application->dealerNotes()->firstOrFail()->text);
        $audit = PaymentAuditLog::where('action', 'funds.applied_to_repairs')->firstOrFail();
        $this->assertSame($lease->id, $audit->lease_agreement_id);
        $this->assertEqualsWithDelta(35.50, (float) $audit->amount, 0.001);
    }

    public function test_funds_cannot_be_applied_to_repairs_in_the_wrong_situation(): void
    {
        $lease = $this->lease();
        $this->extra($lease, 100);
        $url = "/api/admin/lease-agreements/{$lease->id}/additional-funds/repairs";
        $body = ['amount' => 10, 'description' => 'A repair'];

        // The lease is still running.
        $this->asAdmin()->postJson($url, $body)->assertStatus(422)->assertJsonPath('message', fn ($m) => str_contains($m, 'End the lease first'));

        // Bought, not returned.
        $this->end($lease, 'purchased');
        $this->asAdmin()->postJson($url, $body)->assertStatus(422)->assertJsonPath('message', fn ($m) => str_contains($m, 'bought'));
        $lease->forceFill(['ended_reason' => 'returned', 'ownership_status' => LeaseAgreement::OWNERSHIP_LEASING])->save();

        // More than there is, and no description.
        $this->asAdmin()->postJson($url, ['amount' => 100.01, 'description' => 'A repair'])->assertStatus(422)->assertJsonPath('message', fn ($m) => str_contains($m, '$100.00'));
        $this->asAdmin()->postJson($url, ['amount' => 10, 'description' => ''])->assertStatus(422)->assertJsonValidationErrors('description');
        $this->asAdmin()->postJson($url, ['amount' => 10, 'description' => str_repeat('a', 301)])->assertStatus(422)->assertJsonValidationErrors('description');
        $this->asAdmin()->postJson($url, ['amount' => 0, 'description' => 'A repair'])->assertStatus(422)->assertJsonValidationErrors('amount');

        $this->assertSame(0, $lease->additionalFundsEntries()->where('kind', 'repairs')->count());
    }

    public function test_a_contract_signed_before_the_repairs_wording_cannot_have_funds_applied_to_repairs(): void
    {
        $lease = $this->lease([], 1);
        $this->extra($lease, 100);
        $this->end($lease);

        $this->asAdmin()->postJson("/api/admin/lease-agreements/{$lease->id}/additional-funds/repairs", ['amount' => 10, 'description' => 'A repair'])
            ->assertStatus(422)
            ->assertJsonPath('message', fn ($m) => str_contains($m, 'signed before the repairs wording'));
        $this->assertEqualsWithDelta(100.0, (float) $lease->fresh()->additional_funds, 0.001);
    }

    public function test_money_applied_to_repairs_cannot_be_refunded_a_second_time_through_the_payment(): void
    {
        $lease = $this->lease();
        $payment = $this->extra($lease, 100);
        $this->end($lease);
        $this->asAdmin()->postJson("/api/admin/lease-agreements/{$lease->id}/additional-funds/repairs", ['amount' => 30, 'description' => 'A repair'])->assertOk();

        $this->asAdmin()->getJson("/api/admin/payments/{$payment->id}")->assertJsonPath('data.refundable_amount', 70);
        $this->asAdmin()->postJson("/api/admin/payments/{$payment->id}/refunds", ['amount' => 70.01, 'reason' => 'customer_request'])
            ->assertStatus(422)
            ->assertJsonPath('message', 'You can refund at most $70.00 of this payment.');
        $this->asAdmin()->postJson("/api/admin/payments/{$payment->id}/refunds", ['amount' => 70, 'reason' => 'customer_request'])->assertCreated();
        $this->assertEqualsWithDelta(0.0, (float) $lease->fresh()->additional_funds, 0.001);
    }

    // ---- refunding what is left ----

    public function test_the_balance_is_refunded_newest_payment_first_each_the_way_it_came(): void
    {
        $lease = $this->lease();
        $cash = $this->extra($lease, 100);
        $card = $this->extra($lease, 100, ['method' => 'card', 'card_fee_amount' => 3.00, 'stripe_payment_intent_id' => 'pi_card']);
        $this->end($lease);
        $this->asAdmin()->postJson("/api/admin/lease-agreements/{$lease->id}/additional-funds/repairs", ['amount' => 50, 'description' => 'Blade sharpening'])->assertOk();
        $this->stripe->queue($this->stripeRefund('re_1', 10300, 'pi_card'));

        $this->asAdmin()->postJson("/api/admin/lease-agreements/{$lease->id}/additional-funds/refund", ['note' => 'Returned in good shape'])
            ->assertOk()
            ->assertJsonPath('data.balance', 0);

        // 150 left: the card payment first, all of it with its card fee, then 50 of the cash one.
        $refunds = PaymentRefund::orderBy('id')->get();
        $this->assertCount(2, $refunds);
        $this->assertSame($card->id, $refunds[0]->payment_id);
        $this->assertSame('103.00', $refunds[0]->amount);
        $this->assertSame('returned_equipment', $refunds[0]->reason);
        $this->assertSame($cash->id, $refunds[1]->payment_id);
        $this->assertSame('50.00', $refunds[1]->amount);
        $this->assertSame('manual', $refunds[1]->source);
        $this->assertCount(1, $this->stripe->requests);
        $this->assertSame(10300, (int) $this->stripe->requests[0]['params']['amount']);
        $this->assertSame(Payment::STATUS_REFUNDED, $card->fresh()->status);
        $this->assertSame(Payment::STATUS_PAID, $cash->fresh()->status);

        $this->assertSame(['added', 'added', 'repairs', 'refunded', 'refunded'], $lease->additionalFundsEntries()->pluck('kind')->all());
        $this->assertEqualsWithDelta(0.0, (float) $lease->additionalFundsEntries()->reorder()->latest('id')->value('balance_after'), 0.001);
        $this->assertSame('funds.refunded', PaymentAuditLog::where('action', 'funds.refunded')->firstOrFail()->action);
    }

    public function test_part_of_the_balance_can_be_refunded(): void
    {
        $lease = $this->lease();
        $this->extra($lease, 100);
        $this->extra($lease, 40);
        $this->end($lease);

        $this->asAdmin()->postJson("/api/admin/lease-agreements/{$lease->id}/additional-funds/refund", ['amount' => 55])
            ->assertOk()
            ->assertJsonPath('data.balance', 85);

        $this->assertSame(['40.00', '15.00'], PaymentRefund::orderBy('id')->pluck('amount')->all());
    }

    public function test_the_balance_cannot_be_refunded_while_the_lease_runs_or_after_a_purchase_or_for_more_than_is_there(): void
    {
        $lease = $this->lease();
        $this->extra($lease, 100);
        $url = "/api/admin/lease-agreements/{$lease->id}/additional-funds/refund";

        $this->asAdmin()->postJson($url)->assertStatus(422)->assertJsonPath('message', fn ($m) => str_contains($m, 'End the lease first'));

        $this->end($lease, 'purchased');
        $this->asAdmin()->postJson($url)->assertStatus(422);
        $lease->forceFill(['ended_reason' => 'returned', 'ownership_status' => LeaseAgreement::OWNERSHIP_LEASING])->save();

        $this->asAdmin()->postJson($url, ['amount' => 100.01])->assertStatus(422)->assertJsonPath('message', fn ($m) => str_contains($m, '$100.00'));
        $this->assertSame(0, PaymentRefund::count());
    }

    public function test_a_bank_refund_on_its_way_is_not_available_to_refund_again(): void
    {
        $lease = $this->lease();
        $payment = $this->extra($lease, 100, ['method' => 'ach', 'stripe_payment_intent_id' => 'pi_bank']);
        $this->end($lease);
        $this->stripe->queue(['id' => 're_bank', 'object' => 'refund', 'status' => 'pending', 'amount' => 6000, 'currency' => 'usd', 'payment_intent' => 'pi_bank', 'metadata' => []]);

        $this->asAdmin()->postJson("/api/admin/lease-agreements/{$lease->id}/additional-funds/refund", ['amount' => 60])->assertOk();

        // The bank has not confirmed it, so the balance still says 100, but only 40 can still go anywhere.
        $this->assertEqualsWithDelta(100.0, (float) $lease->fresh()->additional_funds, 0.001);
        $this->assertEqualsWithDelta(40.0, AdditionalFundsLedger::available($lease->fresh()), 0.001);
        $this->asAdmin()->postJson("/api/admin/lease-agreements/{$lease->id}/additional-funds/refund", ['amount' => 41])->assertStatus(422);
        $this->assertSame(1, PaymentRefund::count());
        $this->assertSame($payment->id, PaymentRefund::firstOrFail()->payment_id);
    }

    // ---- who can see it ----

    public function test_a_customer_sees_their_own_balance_and_history_without_staff_details(): void
    {
        $lease = $this->lease();
        $this->extra($lease, 100);
        $this->end($lease);
        $this->asAdmin()->postJson("/api/admin/lease-agreements/{$lease->id}/additional-funds/repairs", ['amount' => 20, 'description' => 'New spark plug'])->assertOk();

        $response = $this->actingAs($lease->customer, 'sanctum')->getJson("/api/customer/lease-agreements/{$lease->id}/extra-payment")->assertOk();

        $this->assertEqualsWithDelta(80.0, $response->json('data.additional_funds'), 0.001);
        $this->assertSame(['repairs', 'added'], array_column($response->json('data.history'), 'kind'));
        $this->assertStringContainsString('New spark plug', $response->json('data.history.0.description'));
        $this->assertStringNotContainsString('Stefany', $response->getContent());

        $other = User::factory()->create(['role' => User::ROLE_CUSTOMER]);
        $this->actingAs($other, 'sanctum')->getJson("/api/customer/lease-agreements/{$lease->id}/extra-payment")->assertNotFound();
    }

    public function test_only_staff_with_payment_tracking_can_use_the_funds_screens(): void
    {
        $lease = $this->lease();
        $this->extra($lease, 100);
        $this->end($lease);
        $reviewer = User::factory()->create(['role' => User::ROLE_ADMIN]);
        AdminPermission::create(['user_id' => $reviewer->id, 'permission' => AdminPermission::APPLICATION_REVIEW]);
        $base = "/api/admin/lease-agreements/{$lease->id}/additional-funds";

        foreach ([$lease->customer, $reviewer] as $user) {
            $this->actingAs($user, 'sanctum')->getJson($base)->assertForbidden();
            $this->actingAs($user, 'sanctum')->postJson("{$base}/repairs", ['amount' => 5, 'description' => 'x repair'])->assertForbidden();
            $this->actingAs($user, 'sanctum')->postJson("{$base}/refund")->assertForbidden();
        }
    }
}
