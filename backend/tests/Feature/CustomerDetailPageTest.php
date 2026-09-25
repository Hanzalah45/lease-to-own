<?php

namespace Tests\Feature;

use App\Models\Application;
use App\Models\Contract;
use App\Models\LeaseAgreement;
use App\Models\Payment;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Real gap found live 2026-09-25 (call with Joel): the Customer Accounts
 * page only ever showed bare counts ("2 on file") — to actually see a
 * customer's ID, bill, lease terms, or contract, an admin had to leave and
 * go find the matching row on the separate Applications page. Covers the
 * fix: GET /admin/customers/{id} now returns each application fully
 * presented (equipment, contract, payments, info requests), the same shape
 * the application detail page itself uses.
 */
class CustomerDetailPageTest extends TestCase
{
    use RefreshDatabase;

    public function test_customer_detail_includes_the_full_application_lease_and_contract(): void
    {
        $admin = User::factory()->create(['role' => User::ROLE_ADMIN]);
        $customer = User::factory()->create(['role' => User::ROLE_CUSTOMER]);
        $application = Application::factory()->create(['customer_id' => $customer->id]);
        $lease = LeaseAgreement::factory()->create(['application_id' => $application->id, 'customer_id' => $customer->id]);
        Contract::factory()->create(['lease_agreement_id' => $lease->id, 'signer_user_id' => $customer->id]);
        Payment::factory()->create(['lease_agreement_id' => $lease->id, 'status' => Payment::STATUS_PAID]);

        $response = $this->actingAs($admin, 'sanctum')->getJson("/api/admin/customers/{$customer->id}");

        $response->assertOk();
        $response->assertJsonCount(1, 'data.applications');
        $response->assertJsonPath('data.applications.0.id', $application->id);
        $response->assertJsonPath('data.applications.0.lease_agreement.id', $lease->id);
        $response->assertJsonPath('data.applications.0.lease_agreement.equipment_unit.id', $lease->equipment_unit_id);
        $response->assertJsonPath('data.applications.0.lease_agreement.contract.signer_user_id', $customer->id);
        $response->assertJsonPath('data.applications.0.lease_agreement.payments_made', 1);
        $this->assertIsNumeric($response->json('data.applications.0.lease_agreement.total_monthly_payment'));
    }

    public function test_customer_detail_includes_info_requests(): void
    {
        $admin = User::factory()->create(['role' => User::ROLE_ADMIN]);
        $customer = User::factory()->create(['role' => User::ROLE_CUSTOMER]);
        $application = Application::factory()->create(['customer_id' => $customer->id]);
        $application->infoRequests()->create([
            'requested_by_user_id' => $admin->id,
            'request_text' => 'Do you have a bill for the electricity or utilities?',
        ]);

        $response = $this->actingAs($admin, 'sanctum')->getJson("/api/admin/customers/{$customer->id}");

        $response->assertOk();
        $response->assertJsonCount(1, 'data.applications.0.info_requests');
        $response->assertJsonPath('data.applications.0.info_requests.0.request_text', 'Do you have a bill for the electricity or utilities?');
    }

    public function test_customer_detail_includes_every_application_on_file(): void
    {
        $admin = User::factory()->create(['role' => User::ROLE_ADMIN]);
        $customer = User::factory()->create(['role' => User::ROLE_CUSTOMER]);
        Application::factory()->count(2)->create(['customer_id' => $customer->id]);

        $response = $this->actingAs($admin, 'sanctum')->getJson("/api/admin/customers/{$customer->id}");

        $response->assertJsonCount(2, 'data.applications');
    }

    public function test_it_still_404s_for_a_non_customer_user(): void
    {
        $admin = User::factory()->create(['role' => User::ROLE_ADMIN]);
        $otherAdmin = User::factory()->create(['role' => User::ROLE_ADMIN]);

        $this->actingAs($admin, 'sanctum')->getJson("/api/admin/customers/{$otherAdmin->id}")->assertNotFound();
    }
}
