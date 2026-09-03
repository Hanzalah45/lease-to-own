<?php

namespace Tests\Feature;

use App\Models\AdminPermission;
use App\Models\Application;
use App\Models\Contract;
use App\Models\EquipmentUnit;
use App\Models\LeaseAgreement;
use App\Models\Payment;
use App\Models\RiskProfile;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Covers two gaps found by this session's read-only audit of
 * Admin\ApplicationController::update(): (1) it accepted any status value
 * regardless of the application's current status, and (2) its lease/
 * equipment/risk edit blocks were reachable by any admin with just
 * application_review, bypassing the more granular permissions the UI
 * pretends to enforce.
 */
class ApplicationUpdateGuardsTest extends TestCase
{
    use RefreshDatabase;

    private function restrictedAdmin(string $onlyPermission): User
    {
        $admin = User::factory()->create(['role' => User::ROLE_ADMIN]);
        AdminPermission::create(['user_id' => $admin->id, 'permission' => $onlyPermission]);

        return $admin;
    }

    public function test_status_cannot_jump_past_intermediate_steps(): void
    {
        $application = Application::factory()->create(['status' => Application::STATUS_SUBMITTED]);
        $admin = User::factory()->create(['role' => User::ROLE_SUPER_ADMIN]);

        $response = $this->actingAs($admin, 'sanctum')->putJson("/api/admin/applications/{$application->id}", [
            'status' => Application::STATUS_FUNDED_PAID,
        ]);

        $response->assertStatus(422);
        $this->assertSame(Application::STATUS_SUBMITTED, $application->fresh()->status);
    }

    public function test_status_can_advance_one_legal_step_at_a_time(): void
    {
        $application = Application::factory()->create(['status' => Application::STATUS_SUBMITTED]);
        $admin = User::factory()->create(['role' => User::ROLE_SUPER_ADMIN]);

        $response = $this->actingAs($admin, 'sanctum')->putJson("/api/admin/applications/{$application->id}", [
            'status' => Application::STATUS_UNDER_REVIEW,
        ]);

        $response->assertOk();
        $this->assertSame(Application::STATUS_UNDER_REVIEW, $application->fresh()->status);
    }

    public function test_submitted_can_be_approved_directly_via_the_list_pages_quick_accept(): void
    {
        // admin/applications/page.tsx's one-click "Accept" on a submitted row
        // skips under_review entirely — this must stay legal.
        $application = Application::factory()->create(['status' => Application::STATUS_SUBMITTED]);
        $admin = User::factory()->create(['role' => User::ROLE_SUPER_ADMIN]);

        $response = $this->actingAs($admin, 'sanctum')->putJson("/api/admin/applications/{$application->id}", [
            'status' => Application::STATUS_APPROVED,
        ]);

        $response->assertOk();
        $this->assertSame(Application::STATUS_APPROVED, $application->fresh()->status);
    }

    public function test_declined_can_still_be_reversed_to_submitted(): void
    {
        $application = Application::factory()->create(['status' => Application::STATUS_DECLINED]);
        $admin = User::factory()->create(['role' => User::ROLE_SUPER_ADMIN]);

        $response = $this->actingAs($admin, 'sanctum')->putJson("/api/admin/applications/{$application->id}", [
            'status' => Application::STATUS_SUBMITTED,
        ]);

        $response->assertOk();
    }

    public function test_funded_paid_is_terminal(): void
    {
        $application = Application::factory()->create(['status' => Application::STATUS_FUNDED_PAID]);
        $admin = User::factory()->create(['role' => User::ROLE_SUPER_ADMIN]);

        $response = $this->actingAs($admin, 'sanctum')->putJson("/api/admin/applications/{$application->id}", [
            'status' => Application::STATUS_DECLINED,
        ]);

        $response->assertStatus(422);
    }

    public function test_application_review_only_admin_cannot_edit_lease_terms(): void
    {
        $lease = LeaseAgreement::factory()->create();
        $admin = $this->restrictedAdmin(AdminPermission::APPLICATION_REVIEW);

        $response = $this->actingAs($admin, 'sanctum')->putJson("/api/admin/applications/{$lease->application_id}", [
            'lease' => ['term_months' => 48],
        ]);

        $response->assertStatus(403);
        $this->assertSame(36, $lease->fresh()->term_months);
    }

    public function test_contract_generation_admin_can_edit_lease_terms(): void
    {
        $lease = LeaseAgreement::factory()->create();
        // Reaching this endpoint at all requires application_review (route
        // middleware) — contract_generation is the *additional* permission
        // this fix requires specifically for the lease block.
        $admin = User::factory()->create(['role' => User::ROLE_ADMIN]);
        AdminPermission::create(['user_id' => $admin->id, 'permission' => AdminPermission::APPLICATION_REVIEW]);
        AdminPermission::create(['user_id' => $admin->id, 'permission' => AdminPermission::CONTRACT_GENERATION]);

        $response = $this->actingAs($admin, 'sanctum')->putJson("/api/admin/applications/{$lease->application_id}", [
            'lease' => ['term_months' => 48],
        ]);

        $response->assertOk();
        $this->assertSame(48, $lease->fresh()->term_months);
    }

    public function test_application_review_only_admin_cannot_edit_equipment(): void
    {
        $lease = LeaseAgreement::factory()->create();
        $admin = $this->restrictedAdmin(AdminPermission::APPLICATION_REVIEW);

        $response = $this->actingAs($admin, 'sanctum')->putJson("/api/admin/applications/{$lease->application_id}", [
            'equipment' => ['model' => 'Hacked Model'],
        ]);

        $response->assertStatus(403);
    }

    public function test_application_review_only_admin_cannot_edit_risk_profile(): void
    {
        $lease = LeaseAgreement::factory()->create();
        $admin = $this->restrictedAdmin(AdminPermission::APPLICATION_REVIEW);

        $response = $this->actingAs($admin, 'sanctum')->putJson("/api/admin/applications/{$lease->application_id}", [
            'risk' => ['identity_verification_status' => 'verified'],
        ]);

        $response->assertStatus(403);
    }

    public function test_equipment_edit_rejects_a_duplicate_serial_number(): void
    {
        $takenSerial = EquipmentUnit::factory()->create(['serial_number' => 'TAKEN-123']);
        $lease = LeaseAgreement::factory()->create();
        $admin = User::factory()->create(['role' => User::ROLE_SUPER_ADMIN]);

        $response = $this->actingAs($admin, 'sanctum')->putJson("/api/admin/applications/{$lease->application_id}", [
            'equipment' => ['serial_number' => 'TAKEN-123'],
        ]);

        $response->assertStatus(422);
        $this->assertNotSame('TAKEN-123', $lease->fresh()->equipmentUnit->serial_number);
        $this->assertSame($takenSerial->serial_number, $takenSerial->fresh()->serial_number);
    }

    public function test_editing_lease_terms_after_signing_is_blocked_through_this_endpoint_too(): void
    {
        $lease = LeaseAgreement::factory()->create();
        Contract::factory()->create(['lease_agreement_id' => $lease->id, 'signer_user_id' => $lease->customer_id]);
        $admin = User::factory()->create(['role' => User::ROLE_SUPER_ADMIN]);

        $response = $this->actingAs($admin, 'sanctum')->putJson("/api/admin/applications/{$lease->application_id}", [
            'lease' => ['term_months' => 48],
        ]);

        $response->assertStatus(422);
    }

    public function test_changing_rental_terms_regenerates_an_already_generated_payment_schedule(): void
    {
        $lease = LeaseAgreement::factory()->create(['monthly_rental_payment' => 150, 'term_months' => 36]);
        Payment::create(['lease_agreement_id' => $lease->id, 'amount' => 150, 'due_date' => now()->addMonth(), 'status' => Payment::STATUS_PENDING]);
        $admin = User::factory()->create(['role' => User::ROLE_SUPER_ADMIN]);

        $response = $this->actingAs($admin, 'sanctum')->putJson("/api/admin/applications/{$lease->application_id}", [
            'lease' => ['monthly_rental_payment' => 200],
        ]);

        $response->assertOk();
        $fresh = $lease->fresh();
        $this->assertSame(36, $fresh->payments()->count());
        // Schedule rows are billed at rent + tax, not the bare rental figure.
        $this->assertSame($fresh->totalMonthlyPayment(), (float) $fresh->payments()->first()->amount);
    }

    public function test_changing_rental_terms_is_blocked_once_a_payment_has_been_paid(): void
    {
        $lease = LeaseAgreement::factory()->create(['monthly_rental_payment' => 150, 'term_months' => 36]);
        Payment::create(['lease_agreement_id' => $lease->id, 'amount' => 150, 'due_date' => now()->addMonth(), 'status' => Payment::STATUS_PAID, 'paid_date' => now()]);
        $admin = User::factory()->create(['role' => User::ROLE_SUPER_ADMIN]);

        $response = $this->actingAs($admin, 'sanctum')->putJson("/api/admin/applications/{$lease->application_id}", [
            'lease' => ['monthly_rental_payment' => 200],
        ]);

        $response->assertStatus(422);
    }

    public function test_manual_risk_status_edit_recomputes_the_aggregate_score(): void
    {
        $customer = User::factory()->create(['role' => User::ROLE_CUSTOMER]);
        $application = Application::factory()->create(['customer_id' => $customer->id]);
        $lease = LeaseAgreement::factory()->create(['application_id' => $application->id, 'customer_id' => $customer->id]);
        RiskProfile::create(['customer_id' => $customer->id]);
        $admin = User::factory()->create(['role' => User::ROLE_SUPER_ADMIN]);

        $response = $this->actingAs($admin, 'sanctum')->putJson("/api/admin/applications/{$lease->application_id}", [
            'risk' => [
                'identity_verification_status' => 'verified',
                'employment_verification_status' => 'verified',
                'bank_verification_status' => 'verified',
                'background_check_status' => 'clear',
            ],
        ]);

        $response->assertOk();
        $this->assertSame(100, RiskProfile::where('customer_id', $customer->id)->first()->risk_score);
    }
}
