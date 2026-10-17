<?php

namespace Tests\Feature;

use App\Models\Application;
use App\Models\Contract;
use App\Models\EquipmentUnit;
use App\Models\LeaseAgreement;
use App\Models\Payment;
use App\Models\User;
use App\Services\LeaseEngine;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * Delivered straight from "waiting on deposit" (client, Joel, 2026-10-16: "we need to document the mower as received by the
 * customer now, so do I not mark the deposit as received?"). The customer already has the equipment while the deposit bank
 * debit is still clearing: the delivery is recorded without claiming a deposit that has not arrived.
 */
class DirectDeliveryTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Notification::fake();
        $this->travelTo(Carbon::parse('2026-10-15 15:00:00', 'UTC'));
    }

    /** A signed lease with both methods saved, still waiting on its deposit. */
    private function lease(array $leaseOverrides = [], bool $signed = true): LeaseAgreement
    {
        $customer = User::factory()->create(['role' => User::ROLE_CUSTOMER]);
        $application = Application::factory()->create([
            'customer_id' => $customer->id,
            'status' => Application::STATUS_WAITING_DEPOSIT,
            'deposit_received' => false,
            'signature_received' => false,
        ]);
        $lease = LeaseAgreement::factory()->create(array_merge([
            'application_id' => $application->id,
            'customer_id' => $customer->id,
            'equipment_unit_id' => EquipmentUnit::factory(),
            'term_months' => 36,
            'monthly_rental_payment' => 200,
            'sales_tax_rate' => 0,
            'ldw_selected' => false,
            'billing_cycle' => '15th',
            'stripe_bank_payment_method_id' => 'pm_bank',
            'stripe_card_payment_method_id' => 'pm_card',
        ], $leaseOverrides));
        if ($signed) {
            Contract::factory()->create(['lease_agreement_id' => $lease->id, 'signer_user_id' => $customer->id]);
        }

        return $lease;
    }

    private function deliver(LeaseAgreement $lease, array $body = []): TestResponse
    {
        $admin = User::factory()->create(['role' => User::ROLE_SUPER_ADMIN]);

        return $this->actingAs($admin, 'sanctum')->putJson("/api/admin/applications/{$lease->application_id}", array_merge([
            'status' => Application::STATUS_FINISHED,
            'pickup_date' => '2026-10-15',
        ], $body));
    }

    public function test_a_lease_can_be_delivered_without_marking_the_deposit_received(): void
    {
        $lease = $this->lease();
        // The deposit debit is on its way and has not cleared.
        Payment::factory()->create([
            'lease_agreement_id' => $lease->id,
            'type' => Payment::TYPE_DEPOSIT,
            'amount' => 300,
            'status' => Payment::STATUS_PENDING,
            'stripe_payment_intent_id' => 'pi_deposit_clearing',
        ]);

        $this->deliver($lease)->assertOk()->assertJsonPath('data.status', Application::STATUS_FINISHED);

        $application = $lease->application->fresh();
        $this->assertTrue($application->signature_received);
        $this->assertFalse($application->deposit_received, 'the deposit has not arrived, so it is not marked received');

        // The lease started from the pickup day: the schedule exists and the first month is recorded.
        $this->assertSame('2026-10-15', $lease->equipmentUnit->fresh()->delivery_date->toDateString());
        $this->assertGreaterThan(30, $lease->payments()->where('type', Payment::TYPE_RENTAL)->count());
        $this->assertSame(Payment::STATUS_PAID, $lease->payments()->where('type', Payment::TYPE_RENTAL)->orderBy('due_date')->first()->status);
        // The deposit payment is untouched.
        $this->assertSame(Payment::STATUS_PENDING, $lease->payments()->where('type', Payment::TYPE_DEPOSIT)->first()->status);
    }

    public function test_the_deposit_is_marked_received_by_itself_when_its_payment_clears_afterwards(): void
    {
        $lease = $this->lease();
        $deposit = Payment::factory()->create([
            'lease_agreement_id' => $lease->id,
            'type' => Payment::TYPE_DEPOSIT,
            'amount' => 300,
            'status' => Payment::STATUS_PENDING,
            'stripe_payment_intent_id' => 'pi_deposit_clearing',
        ]);
        $this->deliver($lease)->assertOk();
        $this->assertFalse($lease->application->fresh()->deposit_received);

        LeaseEngine::applyPaymentStatusChange($deposit->fresh(), Payment::STATUS_PAID, 'ach', null);

        $this->assertTrue($lease->application->fresh()->deposit_received);
    }

    public function test_the_same_checks_as_ready_for_pickup_still_apply(): void
    {
        $unsigned = $this->lease([], false);
        $this->deliver($unsigned)->assertStatus(422)->assertJsonPath('message', 'Cannot mark this delivered until the lease contract is signed.');
        $this->assertSame(Application::STATUS_WAITING_DEPOSIT, $unsigned->application->fresh()->status);

        $oneMethod = $this->lease(['stripe_card_payment_method_id' => null]);
        $this->deliver($oneMethod)->assertStatus(422);
        $this->assertSame(Application::STATUS_WAITING_DEPOSIT, $oneMethod->application->fresh()->status);
        $this->assertSame(0, $oneMethod->payments()->count());

        // An admin can still override the second method, as for ready for pickup, and it is recorded on the lease.
        $this->deliver($oneMethod, ['override_payment_methods_check' => true])->assertOk();
        $this->assertNotNull($oneMethod->fresh()->payment_methods_override_at);
    }

    public function test_a_pickup_date_and_billing_cycle_are_still_needed(): void
    {
        $lease = $this->lease(['billing_cycle' => null]);

        $this->deliver($lease)->assertStatus(422);
        $this->deliver($lease, ['pickup_date' => '2026-10-16', 'billing_cycle' => '15th'])->assertStatus(422);
        $this->assertSame(Application::STATUS_WAITING_DEPOSIT, $lease->application->fresh()->status);

        $this->deliver($lease, ['billing_cycle' => '15th'])->assertOk();
    }

    public function test_marking_the_deposit_received_first_still_works_exactly_as_before(): void
    {
        $lease = $this->lease();
        $admin = User::factory()->create(['role' => User::ROLE_SUPER_ADMIN]);

        $this->actingAs($admin, 'sanctum')->putJson("/api/admin/applications/{$lease->application_id}", [
            'status' => Application::STATUS_WAITING_DELIVERY,
        ])->assertOk();

        $application = $lease->application->fresh();
        $this->assertTrue($application->signature_received);
        $this->assertTrue($application->deposit_received);

        $this->deliver($lease)->assertOk()->assertJsonPath('data.status', Application::STATUS_FINISHED);
    }
}
