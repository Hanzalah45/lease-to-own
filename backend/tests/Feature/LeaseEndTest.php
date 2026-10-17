<?php

namespace Tests\Feature;

use App\Models\AdminPermission;
use App\Models\Application;
use App\Models\EquipmentUnit;
use App\Models\LeaseAgreement;
use App\Models\Payment;
use App\Models\User;
use App\Notifications\EquipmentStatusChangedNotification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * Staff end a lease that was already picked up (client, Joel, 2026-10-16: "is it possible for me to cancel those types of
 * situations or will I always need you to do these functions?"). A returned mower must stop being billed, and an early
 * purchase has to be completable.
 */
class LeaseEndTest extends TestCase
{
    use RefreshDatabase;

    /** A picked-up lease with a paid first month, three monthly payments still scheduled and the unit on it. */
    private function lease(array $overrides = [], string $status = Application::STATUS_FINISHED): LeaseAgreement
    {
        $customer = User::factory()->create(['role' => User::ROLE_CUSTOMER]);
        $unit = EquipmentUnit::factory()->create(['status' => EquipmentUnit::STATUS_LEASED, 'model' => 'Ariens 994166']);
        $application = Application::factory()->create(['customer_id' => $customer->id, 'status' => $status]);

        $lease = LeaseAgreement::factory()->create(array_merge([
            'application_id' => $application->id,
            'customer_id' => $customer->id,
            'equipment_unit_id' => $unit->id,
            'autopay_enabled' => true,
            'ownership_status' => LeaseAgreement::OWNERSHIP_LEASING,
        ], $overrides));

        Payment::factory()->create([
            'lease_agreement_id' => $lease->id,
            'type' => Payment::TYPE_RENTAL,
            'amount' => 200,
            'due_date' => '2026-10-01',
            'status' => Payment::STATUS_PAID,
            'paid_date' => '2026-10-01',
        ]);
        foreach (['2026-11-01', '2026-12-01', '2027-01-01'] as $due) {
            Payment::factory()->create([
                'lease_agreement_id' => $lease->id,
                'type' => Payment::TYPE_RENTAL,
                'amount' => 200,
                'due_date' => $due,
                'status' => Payment::STATUS_PENDING,
            ]);
        }

        return $lease;
    }

    private function admin(): User
    {
        return User::factory()->create(['role' => User::ROLE_SUPER_ADMIN]);
    }

    private function end(User $admin, LeaseAgreement $lease, array $body = []): TestResponse
    {
        return $this->actingAs($admin, 'sanctum')->postJson("/api/admin/lease-agreements/{$lease->id}/end", array_merge([
            'reason' => 'returned',
        ], $body));
    }

    public function test_a_returned_lease_stops_being_billed_and_the_unit_comes_back(): void
    {
        Notification::fake();
        $admin = $this->admin();
        $lease = $this->lease();
        $unitId = $lease->equipment_unit_id;

        $this->end($admin, $lease, ['note' => 'Brought back to the shop on the 17th.'])
            ->assertOk()
            ->assertJsonPath('data.removed', 3)
            ->assertJsonPath('data.late_fees_left', 0);

        $fresh = $lease->fresh();
        $this->assertFalse($fresh->autopay_enabled);
        $this->assertTrue($fresh->hasEnded());
        $this->assertSame('returned', $fresh->ended_reason);
        $this->assertSame($admin->id, $fresh->ended_by);
        $this->assertNull($fresh->equipment_unit_id);
        $this->assertSame(EquipmentUnit::STATUS_RETURNED, EquipmentUnit::find($unitId)->status);

        // Only the money that already moved stays; the scheduled months are gone.
        $this->assertSame(1, $lease->payments()->count());
        $this->assertSame(Payment::STATUS_PAID, $lease->payments()->first()->status);

        $note = $lease->application->dealerNotes()->latest('id')->first();
        $this->assertStringContainsString('the equipment was returned', $note->text);
        $this->assertStringContainsString('3 scheduled monthly payments removed', $note->text);
        $this->assertStringContainsString($admin->name, $note->text);
        $this->assertStringContainsString('Brought back to the shop', $note->text);

        Notification::assertSentTo($lease->customer, EquipmentStatusChangedNotification::class);
    }

    public function test_an_early_purchase_needs_the_sales_tax_confirmed_and_then_makes_the_customer_the_owner(): void
    {
        Notification::fake();
        $lease = $this->lease();
        $unitId = $lease->equipment_unit_id;

        $this->end($this->admin(), $lease, ['reason' => 'purchased'])->assertStatus(422);
        $this->end($this->admin(), $lease, ['reason' => 'purchased', 'tax_collected' => false])->assertStatus(422);
        $this->assertFalse($lease->fresh()->hasEnded());
        $this->assertSame(3, $lease->payments()->where('status', Payment::STATUS_PENDING)->count());

        $this->end($this->admin(), $lease, ['reason' => 'purchased', 'tax_collected' => true])->assertOk();

        $fresh = $lease->fresh();
        $this->assertSame(LeaseAgreement::OWNERSHIP_OWNED, $fresh->ownership_status);
        $this->assertSame($unitId, $fresh->equipment_unit_id, 'the paid-off unit stays linked so the history stays attached');
        $this->assertSame(EquipmentUnit::STATUS_OWNED_BY_CUSTOMER, EquipmentUnit::find($unitId)->status);
        $this->assertSame(0, $lease->payments()->where('status', Payment::STATUS_PENDING)->count());
    }

    public function test_another_reason_leaves_the_equipment_alone(): void
    {
        $lease = $this->lease();
        $unitId = $lease->equipment_unit_id;

        $this->end($this->admin(), $lease, ['reason' => 'other'])->assertOk();

        $this->assertSame($unitId, $lease->fresh()->equipment_unit_id);
        $this->assertSame(EquipmentUnit::STATUS_LEASED, EquipmentUnit::find($unitId)->status);
        $this->assertSame(0, $lease->payments()->where('status', Payment::STATUS_PENDING)->count());
    }

    public function test_a_late_fee_already_charged_is_kept_and_counted(): void
    {
        $lease = $this->lease();
        Payment::factory()->create([
            'lease_agreement_id' => $lease->id,
            'type' => Payment::TYPE_LATE_FEE,
            'amount' => 20,
            'due_date' => '2026-10-12',
            'status' => Payment::STATUS_PENDING,
        ]);

        $this->end($this->admin(), $lease)->assertOk()->assertJsonPath('data.late_fees_left', 1);

        $this->assertSame(1, $lease->payments()->where('type', Payment::TYPE_LATE_FEE)->count());
        $this->assertStringContainsString('1 unpaid late fee remain owed', $lease->application->dealerNotes()->latest('id')->first()->text);
    }

    public function test_it_waits_while_a_payment_is_still_processing(): void
    {
        $lease = $this->lease();
        Payment::factory()->create([
            'lease_agreement_id' => $lease->id,
            'type' => Payment::TYPE_RENTAL,
            'amount' => 200,
            'due_date' => '2026-10-15',
            'status' => Payment::STATUS_PENDING,
            'stripe_payment_intent_id' => 'pi_bank_in_flight',
        ]);

        $this->end($this->admin(), $lease)->assertStatus(422);

        $this->assertFalse($lease->fresh()->hasEnded());
        $this->assertSame(3, $lease->payments()->where('status', Payment::STATUS_PENDING)->whereNull('stripe_payment_intent_id')->count());
    }

    public function test_it_only_ends_a_lease_that_was_picked_up_and_only_once(): void
    {
        $early = $this->lease([], Application::STATUS_WAITING_DEPOSIT);
        $this->end($this->admin(), $early)->assertStatus(422);

        $paidOff = $this->lease(['ownership_status' => LeaseAgreement::OWNERSHIP_OWNED]);
        $this->end($this->admin(), $paidOff)->assertStatus(422);

        $lease = $this->lease();
        $this->end($this->admin(), $lease)->assertOk();
        $this->end($this->admin(), $lease)->assertStatus(422);
    }

    public function test_an_ended_lease_cannot_take_an_extra_payment(): void
    {
        $lease = $this->lease(['stripe_card_payment_method_id' => 'pm_card']);
        $this->end($this->admin(), $lease)->assertOk();

        $this->actingAs($lease->customer, 'sanctum')->getJson("/api/customer/lease-agreements/{$lease->id}/extra-payment")
            ->assertOk()
            ->assertJsonPath('data.available', false)
            ->assertJsonPath('data.blocker', 'This lease has ended.');
    }

    public function test_only_staff_with_payment_tracking_can_end_a_lease(): void
    {
        $lease = $this->lease();

        $this->end($lease->customer, $lease)->assertForbidden();

        $reviewer = User::factory()->create(['role' => User::ROLE_ADMIN]);
        AdminPermission::create(['user_id' => $reviewer->id, 'permission' => AdminPermission::APPLICATION_REVIEW]);
        $this->end($reviewer, $lease)->assertForbidden();

        $this->assertFalse($lease->fresh()->hasEnded());
    }
}
