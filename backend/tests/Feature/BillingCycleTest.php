<?php

namespace Tests\Feature;

use App\Models\Application;
use App\Models\Contract;
use App\Models\EquipmentUnit;
use App\Models\LeaseAgreement;
use App\Models\Payment;
use App\Models\User;
use App\Services\ContractSigner;
use App\Services\LeaseEngine;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

/**
 * Billing cycles (client, Joel, 2026-10-05): the customer picks the 1st or
 * 15th right before signing, and the monthly schedule is built when the
 * equipment is picked up. The date arithmetic itself is in
 * Tests\Unit\BillingScheduleTest; this covers the wiring.
 */
class BillingCycleTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        // 1pm Central on Oct 5, 2026 (same calendar date in UTC and Texas).
        $this->travelTo(Carbon::parse('2026-10-05 18:00:00', 'UTC'));
    }

    private function lease(array $overrides = [], string $applicationStatus = Application::STATUS_WAITING_DEPOSIT): LeaseAgreement
    {
        $customer = User::factory()->create(['role' => User::ROLE_CUSTOMER]);
        $application = Application::factory()->create(['customer_id' => $customer->id, 'status' => $applicationStatus]);

        return LeaseAgreement::factory()->create(array_merge([
            'application_id' => $application->id,
            'customer_id' => $customer->id,
            'equipment_unit_id' => EquipmentUnit::factory(),
            'term_months' => 36,
            'monthly_rental_payment' => 200,
            'sales_tax_rate' => 0,
            'ldw_selected' => false,
            'billing_cycle' => '15th',
        ], $overrides));
    }

    // ---- LeaseEngine::startLease ----

    public function test_starting_a_lease_builds_the_schedule_from_the_pickup_date(): void
    {
        $admin = User::factory()->create(['role' => User::ROLE_ADMIN]);
        $lease = $this->lease();

        $first = LeaseEngine::startLease($lease, '2026-10-07', $admin->id);

        $this->assertSame(Payment::STATUS_PAID, $first->status);
        $this->assertSame('2026-10-07', $first->due_date->toDateString());
        $this->assertSame($admin->id, $first->recorded_by);

        $rows = $lease->payments()->where('type', Payment::TYPE_RENTAL)->orderBy('due_date')->get();
        $this->assertCount(36, $rows);
        $this->assertSame('2026-10-15', $rows[1]->due_date->toDateString());
        $this->assertSame('53.33', $rows[1]->amount); // 200 x 8/30
        $this->assertSame('200.00', $rows[2]->amount);
        $this->assertSame('2029-08-15', $rows[35]->due_date->toDateString());
        $this->assertSame(1, $rows->where('status', Payment::STATUS_PAID)->count());

        $fresh = $lease->fresh();
        $this->assertSame('2026-10-07', $fresh->start_date->toDateString());
        $this->assertSame('2026-10-15', $fresh->renewal_date->toDateString());
        $this->assertSame('2029-08-15', $fresh->equipmentUnit->expected_return_or_ownership_date->toDateString());
        $this->assertSame('200.00', $fresh->rental_payments_paid_to_date);
    }

    public function test_starting_a_lease_twice_changes_nothing(): void
    {
        $lease = $this->lease();

        $first = LeaseEngine::startLease($lease, '2026-10-07', null);
        $again = LeaseEngine::startLease($lease->fresh(), '2026-10-09', null);

        $this->assertSame($first->id, $again->id);
        $this->assertSame(36, $lease->payments()->count());
        $this->assertSame('2026-10-07', $lease->fresh()->start_date->toDateString());
    }

    public function test_starting_a_lease_replaces_a_legacy_schedule_built_at_creation(): void
    {
        $lease = $this->lease(['start_date' => '2026-09-01']);
        LeaseEngine::generatePaymentSchedule($lease); // the old behavior: 36 pending rows dated from creation
        $this->assertSame('2026-10-01', $lease->payments()->orderBy('due_date')->first()->due_date->toDateString());

        LeaseEngine::startLease($lease->fresh(), '2026-10-07', null);

        $rows = $lease->payments()->where('type', Payment::TYPE_RENTAL)->orderBy('due_date')->get();
        $this->assertCount(36, $rows);
        $this->assertSame('2026-10-07', $rows[0]->due_date->toDateString());
    }

    public function test_starting_a_lease_never_touches_deposit_or_pickup_balance_rows(): void
    {
        $lease = $this->lease();
        $deposit = Payment::factory()->create(['lease_agreement_id' => $lease->id, 'type' => Payment::TYPE_DEPOSIT, 'status' => Payment::STATUS_PAID]);
        $balance = Payment::factory()->create(['lease_agreement_id' => $lease->id, 'type' => Payment::TYPE_PICKUP_BALANCE, 'status' => Payment::STATUS_PAID]);

        LeaseEngine::startLease($lease, '2026-10-07', null);

        $this->assertNotNull($deposit->fresh());
        $this->assertNotNull($balance->fresh());
        $this->assertSame(36, $lease->payments()->where('type', Payment::TYPE_RENTAL)->count());
    }

    public function test_starting_a_lease_requires_a_billing_cycle(): void
    {
        $lease = $this->lease(['billing_cycle' => null]);

        $this->expectException(HttpException::class);
        LeaseEngine::startLease($lease, '2026-10-07', null);
    }

    public function test_starting_a_lease_is_refused_when_a_rental_charge_is_already_in_flight(): void
    {
        $lease = $this->lease();
        Payment::factory()->create([
            'lease_agreement_id' => $lease->id,
            'status' => Payment::STATUS_PENDING,
            'stripe_payment_intent_id' => 'pi_inflight',
        ]);

        $this->expectException(HttpException::class);
        LeaseEngine::startLease($lease, '2026-10-07', null);
    }

    // ---- signing ----

    public function test_signing_saves_the_chosen_billing_cycle_and_states_it_in_the_contract(): void
    {
        Notification::fake();
        $lease = $this->lease(['billing_cycle' => null]);

        $this->actingAs($lease->customer, 'sanctum')
            ->postJson('/api/customer/contracts', ['lease_agreement_id' => $lease->id, 'signer_name' => 'Pat Customer', 'billing_cycle' => '1st'])
            ->assertCreated();

        $this->assertSame('1st', $lease->fresh()->billing_cycle);
        $html = Contract::where('lease_agreement_id', $lease->id)->firstOrFail()->document_html;
        $this->assertStringContainsString('The 1st of each month', $html);
        $this->assertStringContainsString('Billing Cycle', $html);
        // Dual pricing (2026-10-05): the contract states both prices. $200 monthly -> $206.00 by card.
        $this->assertStringContainsString('Payment price by method', $html);
        $this->assertStringContainsString('$206.00', $html);
        // The client's approved wording (2026-10-06); line breaks in the template are not part of the text.
        $text = preg_replace('/\s+/', ' ', strip_tags($html));
        $this->assertStringContainsString('the card payment amount will be 3% higher than the applicable ACH payment amount', $text);
        $this->assertStringContainsString('the applicable 3% card processing fee will be added to the ACH payment amount', $text);
        $this->assertStringContainsString('Your first Rental Payment, equal to one full monthly payment, is due on the date you take possession of the Property.', $text);
        $this->assertStringContainsString('There will be no final catch-up payment at the end of the scheduled term.', $text);
    }

    public function test_the_autopay_page_carries_the_clients_card_price_and_fallback_wording(): void
    {
        Notification::fake();
        $lease = $this->lease(['billing_cycle' => null, 'autopay_enabled' => true]);

        $this->actingAs($lease->customer, 'sanctum')
            ->postJson('/api/customer/contracts', ['lease_agreement_id' => $lease->id, 'signer_name' => 'Pat Customer', 'billing_cycle' => '15th'])
            ->assertCreated();

        $html = Contract::where('lease_agreement_id', $lease->id)->firstOrFail()->document_html;
        $text = preg_replace('/\s+/', ' ', html_entity_decode(strip_tags($html)));
        $this->assertStringContainsString('AutoPay Notice. If a payment charged through AutoPay is more than $30 above the regular payment amount', $text);
        $this->assertStringContainsString('the applicable card payment price, including the 3% card processing fee, will apply.', $text);
        $this->assertStringContainsString('as described in Section 2, Lease Term & Payment Schedule.', $text);
        $this->assertStringContainsString('AutoPay Revocation. You may revoke your AutoPay authorization by providing written notice at least three (3) business days', $text);
    }

    public function test_signing_is_refused_without_a_billing_cycle(): void
    {
        $lease = $this->lease(['billing_cycle' => null]);

        $this->actingAs($lease->customer, 'sanctum')
            ->postJson('/api/customer/contracts', ['lease_agreement_id' => $lease->id, 'signer_name' => 'Pat Customer'])
            ->assertStatus(422);

        $this->assertSame(0, Contract::count());
    }

    public function test_an_admin_preselected_cycle_is_used_when_the_customer_does_not_change_it(): void
    {
        Notification::fake();
        $lease = $this->lease(['billing_cycle' => '15th']);

        $this->actingAs($lease->customer, 'sanctum')
            ->postJson('/api/customer/contracts', ['lease_agreement_id' => $lease->id, 'signer_name' => 'Pat Customer'])
            ->assertCreated();

        $this->assertSame('15th', $lease->fresh()->billing_cycle);
    }

    public function test_the_customers_choice_at_signing_overrides_an_admin_preselection(): void
    {
        Notification::fake();
        $lease = $this->lease(['billing_cycle' => '15th']);

        $this->actingAs($lease->customer, 'sanctum')
            ->postJson('/api/customer/contracts', ['lease_agreement_id' => $lease->id, 'signer_name' => 'Pat Customer', 'billing_cycle' => '1st'])
            ->assertCreated();

        $this->assertSame('1st', $lease->fresh()->billing_cycle);
    }

    public function test_an_unknown_cycle_is_rejected(): void
    {
        $lease = $this->lease(['billing_cycle' => null]);

        $this->actingAs($lease->customer, 'sanctum')
            ->postJson('/api/customer/contracts', ['lease_agreement_id' => $lease->id, 'signer_name' => 'Pat Customer', 'billing_cycle' => '20th'])
            ->assertStatus(422);
    }

    public function test_the_guest_signed_link_also_requires_and_saves_the_cycle(): void
    {
        Notification::fake();
        $lease = $this->lease(['billing_cycle' => null]);
        $lease->customer->update(['status' => 'pending']);
        parse_str(parse_url(ContractSigner::urlFor($lease->customer, $lease), PHP_URL_QUERY), $params);

        $this->postJson('/api/contracts/verify-sign', [...$params, 'signer_name' => 'Pat Guest'])->assertStatus(422);
        $this->postJson('/api/contracts/verify-sign', [...$params, 'signer_name' => 'Pat Guest', 'billing_cycle' => '15th'])->assertCreated();

        $this->assertSame('15th', $lease->fresh()->billing_cycle);
    }

    // ---- preview ----

    public function test_previewing_with_a_cycle_renders_the_contract_without_saving_it(): void
    {
        $lease = $this->lease(['billing_cycle' => null]);

        $response = $this->actingAs($lease->customer, 'sanctum')
            ->get("/api/customer/lease-agreements/{$lease->id}/contract-preview?billing_cycle=15th");

        $response->assertOk();
        $this->assertStringStartsWith('%PDF', $response->getContent());
        $this->assertNull($lease->fresh()->billing_cycle);
    }

    public function test_previewing_with_an_unknown_cycle_is_rejected(): void
    {
        $lease = $this->lease(['billing_cycle' => null]);

        $this->actingAs($lease->customer, 'sanctum')
            ->getJson("/api/customer/lease-agreements/{$lease->id}/contract-preview?billing_cycle=20th")
            ->assertStatus(422);
    }

    public function test_the_signing_page_gets_a_preview_of_both_cycles(): void
    {
        $lease = $this->lease(['billing_cycle' => null]);

        $response = $this->actingAs($lease->customer, 'sanctum')->getJson("/api/customer/lease-agreements/{$lease->id}");

        $response->assertOk();
        // Picked up "today" (Oct 5), the 15th is 10 days away: 200 x 10/30 = 66.67.
        $response->assertJsonPath('data.billing_preview.15th.second_payment_date', '2026-10-15');
        $response->assertJsonPath('data.billing_preview.15th.second_payment_amount', 66.67);
        $response->assertJsonPath('data.billing_preview.15th.second_payment_prorated', true);
        // The 1st is 27 days away: 200 x 27/30 = 180.
        $response->assertJsonPath('data.billing_preview.1st.second_payment_amount', 180);
    }

    // ---- admin: Mark Delivered ----

    private function signedLeaseWaitingForDelivery(string $cycle = '15th'): array
    {
        $lease = $this->lease(['billing_cycle' => $cycle], Application::STATUS_WAITING_DELIVERY);
        Contract::factory()->create(['lease_agreement_id' => $lease->id, 'signer_user_id' => $lease->customer_id]);
        $admin = User::factory()->create(['role' => User::ROLE_SUPER_ADMIN]);

        return [$lease, $admin];
    }

    public function test_marking_delivered_with_an_earlier_pickup_date_anchors_the_schedule_to_it(): void
    {
        Notification::fake();
        [$lease, $admin] = $this->signedLeaseWaitingForDelivery();

        $this->actingAs($admin, 'sanctum')->putJson("/api/admin/applications/{$lease->application_id}", [
            'status' => Application::STATUS_FINISHED,
            'pickup_date' => '2026-10-02',
        ])->assertOk();

        $this->assertSame('2026-10-02', $lease->payments()->orderBy('due_date')->first()->due_date->toDateString());
        $this->assertSame('2026-10-02', $lease->equipmentUnit->fresh()->delivery_date->toDateString());
    }

    public function test_a_future_pickup_date_is_rejected_and_nothing_changes(): void
    {
        [$lease, $admin] = $this->signedLeaseWaitingForDelivery();

        $this->actingAs($admin, 'sanctum')->putJson("/api/admin/applications/{$lease->application_id}", [
            'status' => Application::STATUS_FINISHED,
            'pickup_date' => '2026-10-06',
        ])->assertStatus(422);

        $this->assertSame(Application::STATUS_WAITING_DELIVERY, $lease->application->fresh()->status);
        $this->assertSame(0, $lease->payments()->count());
    }

    public function test_a_pickup_date_more_than_a_week_back_is_rejected(): void
    {
        [$lease, $admin] = $this->signedLeaseWaitingForDelivery();

        $this->actingAs($admin, 'sanctum')->putJson("/api/admin/applications/{$lease->application_id}", [
            'status' => Application::STATUS_FINISHED,
            'pickup_date' => '2026-09-27',
        ])->assertStatus(422);
    }

    public function test_pickup_defaults_to_today_in_texas_even_when_utc_is_already_tomorrow(): void
    {
        Notification::fake();
        // 9pm Central on Oct 14 is already Oct 15 in UTC.
        $this->travelTo(Carbon::parse('2026-10-15 02:00:00', 'UTC'));
        [$lease, $admin] = $this->signedLeaseWaitingForDelivery('15th');

        $this->actingAs($admin, 'sanctum')->putJson("/api/admin/applications/{$lease->application_id}", [
            'status' => Application::STATUS_FINISHED,
        ])->assertOk();

        $rows = $lease->payments()->orderBy('due_date')->get();
        $this->assertSame('2026-10-14', $rows[0]->due_date->toDateString());
        $this->assertSame('2026-10-15', $rows[1]->due_date->toDateString());
        $this->assertSame('6.67', $rows[1]->amount); // one prorated day: 200 / 30
    }
}
