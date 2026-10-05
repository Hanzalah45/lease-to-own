<?php

namespace Tests\Feature;

use App\Models\AdminPermission;
use App\Models\Application;
use App\Models\Contract;
use App\Models\EquipmentUnit;
use App\Models\LeaseAgreement;
use App\Models\Payment;
use App\Models\User;
use App\Notifications\ContractVoidedNotification;
use App\Notifications\RequestContractSignatureNotification;
use App\Services\ApplicationCreationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

/**
 * Changing the mower on an existing application (client, Joel, 2026-10-06):
 * a customer changes their mind, usually over the price, and the lease has to
 * be re-priced for the new mower.
 */
class ChangeEquipmentTest extends TestCase
{
    use RefreshDatabase;

    private function admin(): User
    {
        return User::factory()->create(['role' => User::ROLE_SUPER_ADMIN]);
    }

    /** An application with a real equipment unit and a lease, built the same way the app builds them. */
    private function application(string $status = Application::STATUS_WAITING_DEPOSIT, array $customer = []): Application
    {
        $user = User::factory()->create(array_merge(['role' => User::ROLE_CUSTOMER], $customer));
        $application = Application::factory()->create(['customer_id' => $user->id, 'status' => $status]);

        ApplicationCreationService::attachLease($application, [
            'make' => 'Toro', 'model' => 'Z Master', 'serial' => 'OLD-1', 'condition' => 'used',
            'cash_price' => 3000, 'term_months' => 36, 'tax_rate' => 0, 'ldw' => 'yes',
            'monthly_rental' => 0,
        ]);

        return $application->fresh();
    }

    private function payload(array $overrides = []): array
    {
        return array_merge([
            'make' => 'Cub Cadet',
            'model' => 'XT1',
            'serial' => 'NEW-1',
            'condition' => 'new',
            'year' => '2025',
            'cash_price' => 2400,
            'term_months' => 24,
            'tax_rate' => 0,
            'ldw' => 'no',
        ], $overrides);
    }

    private function change(Application $application, array $overrides = [], ?User $as = null)
    {
        return $this->actingAs($as ?? $this->admin(), 'sanctum')
            ->postJson("/api/admin/applications/{$application->id}/change-equipment", $this->payload($overrides));
    }

    public function test_changing_the_mower_before_signing_re_prices_the_lease(): void
    {
        $application = $this->application();
        $unitId = $application->leaseAgreement->equipment_unit_id;

        $this->change($application)
            ->assertOk()
            ->assertJsonPath('meta.contract_voided', false)
            ->assertJsonPath('data.lease_agreement.total_monthly_payment', 150);

        $lease = $application->fresh()->leaseAgreement;
        $this->assertEqualsWithDelta(150.0, (float) $lease->monthly_rental_payment, 0.001);
        $this->assertEqualsWithDelta(450.0, (float) $lease->security_deposit, 0.001);
        $this->assertEqualsWithDelta(3600.0, (float) $lease->total_rental_purchase_price, 0.001);
        $this->assertEqualsWithDelta(2400.0, (float) $lease->cash_price, 0.001);
        $this->assertSame(24, $lease->term_months);
        $this->assertFalse($lease->ldw_selected);
        $this->assertEqualsWithDelta(0.0, (float) $lease->ldw_amount, 0.001);

        // The application's own placeholder unit is edited in place, not duplicated.
        $this->assertSame($unitId, $lease->equipment_unit_id);
        $unit = $lease->equipmentUnit;
        $this->assertSame('Cub Cadet XT1', $unit->model);
        $this->assertSame('NEW-1', $unit->serial_number);
        $this->assertStringContainsString('Year: 2025', $unit->condition_notes);
        $this->assertSame(now()->addMonthsNoOverflow(24)->toDateString(), $unit->expected_return_or_ownership_date->toDateString());
        $this->assertSame(1, EquipmentUnit::count());
    }

    public function test_it_leaves_a_note_on_the_application(): void
    {
        $application = $this->application();

        $this->change($application);

        $note = $application->dealerNotes()->sole();
        $this->assertStringContainsString('Toro Z Master', $note->text);
        $this->assertStringContainsString('Cub Cadet XT1', $note->text);
        $this->assertStringContainsString('$150.00/mo', $note->text);
    }

    public function test_repricing_the_same_mower_is_worded_as_a_price_change(): void
    {
        $application = $this->application();
        Contract::factory()->create(['lease_agreement_id' => $application->leaseAgreement->id, 'signer_user_id' => $application->customer_id]);

        $this->change($application, ['make' => 'Toro', 'model' => 'Z Master', 'serial' => 'OLD-1', 'cash_price' => 2800, 'void_signed_contract' => true])->assertOk();

        $this->assertStringContainsString('Lease re-priced for Toro Z Master', $application->dealerNotes()->sole()->text);
        $this->assertSame('The lease for Toro Z Master was re-priced.', $application->leaseAgreement->contracts()->sole()->void_reason);
    }

    public function test_an_existing_serial_on_another_unit_is_made_unique(): void
    {
        $application = $this->application();
        EquipmentUnit::factory()->create(['serial_number' => 'TAKEN-9']);

        $this->change($application, ['serial' => 'TAKEN-9'])->assertOk();

        $serial = $application->fresh()->leaseAgreement->equipmentUnit->serial_number;
        $this->assertStringStartsWith('TAKEN-9-', $serial);
    }

    public function test_keeping_the_same_serial_is_fine(): void
    {
        $application = $this->application();

        $this->change($application, ['serial' => 'OLD-1', 'cash_price' => 2000])->assertOk();

        $this->assertSame('OLD-1', $application->fresh()->leaseAgreement->equipmentUnit->serial_number);
    }

    public function test_a_signed_contract_must_be_confirmed_before_it_is_voided(): void
    {
        $application = $this->application();
        $lease = $application->leaseAgreement;
        Contract::factory()->create(['lease_agreement_id' => $lease->id, 'signer_user_id' => $application->customer_id]);

        $this->change($application)->assertStatus(422);

        $this->assertEqualsWithDelta(3000.0, (float) $lease->fresh()->cash_price, 0.001);
        $this->assertNotNull($lease->fresh()->contract);
    }

    public function test_confirming_voids_the_signature_and_the_customer_signs_again(): void
    {
        Notification::fake();
        $application = $this->application(Application::STATUS_WAITING_DELIVERY);
        $application->update(['signature_received' => true]);
        $lease = $application->leaseAgreement;
        $contract = Contract::factory()->create(['lease_agreement_id' => $lease->id, 'signer_user_id' => $application->customer_id]);

        $this->change($application, ['void_signed_contract' => true])
            ->assertOk()
            ->assertJsonPath('meta.contract_voided', true);

        $this->assertNotNull($contract->fresh()->voided_at);
        $this->assertStringContainsString('Cub Cadet XT1', $contract->fresh()->void_reason);
        $this->assertNull($lease->fresh()->contract);
        $application->refresh();
        $this->assertFalse($application->signature_received);
        // "Ready for pickup" needs a signed contract, so it steps back.
        $this->assertSame(Application::STATUS_WAITING_DEPOSIT, $application->status);
        Notification::assertSentTo($application->customer, ContractVoidedNotification::class);
    }

    public function test_a_guest_customer_gets_a_fresh_signing_link(): void
    {
        Notification::fake();
        $application = $this->application(Application::STATUS_WAITING_DEPOSIT, ['status' => 'pending']);
        Contract::factory()->create(['lease_agreement_id' => $application->leaseAgreement->id, 'signer_user_id' => $application->customer_id]);

        $this->change($application, ['void_signed_contract' => true])->assertOk();

        Notification::assertSentTo($application->customer, RequestContractSignatureNotification::class);
        Notification::assertNotSentTo($application->customer, ContractVoidedNotification::class);
    }

    public function test_an_unsigned_change_sends_the_customer_nothing(): void
    {
        Notification::fake();
        $application = $this->application();

        $this->change($application)->assertOk();

        Notification::assertNothingSent();
    }

    public function test_it_is_refused_once_a_payment_has_been_collected(): void
    {
        $application = $this->application();
        Payment::factory()->create([
            'lease_agreement_id' => $application->leaseAgreement->id,
            'type' => Payment::TYPE_DEPOSIT,
            'status' => Payment::STATUS_PAID,
        ]);

        $this->change($application)->assertStatus(422);

        $this->assertEqualsWithDelta(3000.0, (float) $application->fresh()->leaseAgreement->cash_price, 0.001);
    }

    public function test_it_is_refused_when_the_deposit_was_marked_received_by_hand(): void
    {
        $application = $this->application();
        $application->update(['deposit_received' => true]);

        $this->change($application)->assertStatus(422);
    }

    public function test_it_is_refused_while_a_charge_is_still_in_flight(): void
    {
        $application = $this->application();
        Payment::factory()->create([
            'lease_agreement_id' => $application->leaseAgreement->id,
            'type' => Payment::TYPE_DEPOSIT,
            'status' => Payment::STATUS_PENDING,
            'stripe_payment_intent_id' => 'pi_processing',
        ]);

        $this->change($application)->assertStatus(422);
    }

    public function test_a_failed_deposit_attempt_does_not_block_it(): void
    {
        $application = $this->application();
        Payment::factory()->create([
            'lease_agreement_id' => $application->leaseAgreement->id,
            'type' => Payment::TYPE_DEPOSIT,
            'status' => Payment::STATUS_FAILED,
        ]);

        $this->change($application)->assertOk();
    }

    public function test_it_is_refused_after_pickup_or_on_a_closed_application(): void
    {
        foreach ([Application::STATUS_FINISHED, Application::STATUS_DECLINED, Application::STATUS_WITHDRAWN] as $status) {
            $application = $this->application($status);

            $this->change($application)->assertStatus(422);
        }
    }

    public function test_it_is_refused_when_there_is_no_lease_yet(): void
    {
        $application = Application::factory()->create(['status' => Application::STATUS_WAITING_REVIEW]);

        $this->change($application)->assertStatus(422);
    }

    public function test_a_fleet_unit_already_assigned_goes_back_to_stock(): void
    {
        $application = $this->application();
        $lease = $application->leaseAgreement;
        $fleetUnit = $lease->equipmentUnit;
        // Handed to this lease from stock, so it counts as a real fleet machine.
        $fleetUnit->update(['delivery_date' => now()->toDateString()]);

        $this->change($application)->assertOk();

        $this->assertSame(EquipmentUnit::STATUS_IN_STOCK, $fleetUnit->fresh()->status);
        $this->assertNull($fleetUnit->fresh()->delivery_date);
        $newUnit = $lease->fresh()->equipmentUnit;
        $this->assertNotSame($fleetUnit->id, $newUnit->id);
        $this->assertSame('Cub Cadet XT1', $newUnit->model);
        $this->assertSame(EquipmentUnit::STATUS_LEASED, $newUnit->status);
    }

    public function test_the_mower_must_be_named_and_the_term_priced(): void
    {
        $application = $this->application();
        $admin = $this->admin();

        $this->change($application, ['make' => null, 'model' => null], $admin)->assertStatus(422)->assertJsonValidationErrors('make');
        $this->change($application, ['term_months' => 18], $admin)->assertStatus(422)->assertJsonValidationErrors('term_months');
        $this->change($application, ['cash_price' => 0], $admin)->assertStatus(422)->assertJsonValidationErrors('cash_price');
    }

    public function test_it_needs_both_lease_and_equipment_permissions(): void
    {
        $application = $this->application();
        $reviewer = User::factory()->create(['role' => User::ROLE_ADMIN]);
        AdminPermission::create(['user_id' => $reviewer->id, 'permission' => AdminPermission::APPLICATION_REVIEW]);

        $this->change($application, [], $reviewer)->assertForbidden();

        AdminPermission::create(['user_id' => $reviewer->id, 'permission' => AdminPermission::CONTRACT_GENERATION]);
        $this->change($application, [], $reviewer)->assertForbidden();

        AdminPermission::create(['user_id' => $reviewer->id, 'permission' => AdminPermission::EQUIPMENT_TRACKING]);
        $this->change($application, [], $reviewer)->assertOk();
    }

    public function test_a_customer_cannot_change_a_mower(): void
    {
        $application = $this->application();

        $this->change($application, [], $application->customer)->assertForbidden();
    }

    public function test_the_changed_lease_prices_the_same_as_the_calculator(): void
    {
        $application = $this->application();
        $admin = $this->admin();

        $this->change($application, ['cash_price' => 4899, 'term_months' => 36, 'ldw' => 'yes', 'tax_rate' => 8.25], $admin)->assertOk();

        $quote = $this->actingAs($admin, 'sanctum')->postJson('/api/admin/pricing/quote', [
            'cash_price' => 4899, 'term_months' => 36, 'ldw' => 'yes', 'tax_rate' => 8.25,
        ])->json('data');
        $lease = $application->fresh()->leaseAgreement;
        $this->assertEqualsWithDelta($quote['total_monthly_payment'], $lease->totalMonthlyPayment(), 0.001);
        $this->assertEqualsWithDelta($quote['security_deposit'], (float) $lease->security_deposit, 0.001);
        $this->assertInstanceOf(LeaseAgreement::class, $lease);
    }
}
