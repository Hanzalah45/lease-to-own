<?php

namespace Tests\Feature;

use App\Models\Application;
use App\Models\Contract;
use App\Models\LeaseAgreement;
use App\Models\User;
use App\Notifications\ContractVoidedNotification;
use App\Services\ContractPdfService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Covers the full contract lifecycle built this session: signing is gated on
 * application approval, a signed lease can't be edited or double-signed, and
 * voiding a signature (not deleting it) is what clears the way to re-sign.
 */
class ContractSigningTest extends TestCase
{
    use RefreshDatabase;

    private function leaseFor(string $applicationStatus): LeaseAgreement
    {
        $customer = User::factory()->create(['role' => User::ROLE_CUSTOMER]);
        $application = Application::factory()->create([
            'customer_id' => $customer->id,
            'status' => $applicationStatus,
        ]);

        return LeaseAgreement::factory()->create([
            'application_id' => $application->id,
            'customer_id' => $customer->id,
        ]);
    }

    public function test_cannot_sign_before_application_is_approved(): void
    {
        $lease = $this->leaseFor(Application::STATUS_UNDER_REVIEW);

        $response = $this->actingAs($lease->customer, 'sanctum')->postJson('/api/customer/contracts', [
            'lease_agreement_id' => $lease->id,
            'signer_name' => 'Test Signer',
        ]);

        $response->assertStatus(422);
        $this->assertDatabaseCount('contracts', 0);
    }

    public function test_can_sign_once_application_is_approved(): void
    {
        $lease = $this->leaseFor(Application::STATUS_APPROVED);

        $response = $this->actingAs($lease->customer, 'sanctum')->postJson('/api/customer/contracts', [
            'lease_agreement_id' => $lease->id,
            'signer_name' => 'Test Signer',
        ]);

        $response->assertCreated();
        $this->assertDatabaseHas('contracts', [
            'lease_agreement_id' => $lease->id,
            'version' => 1,
            'voided_at' => null,
        ]);
    }

    public function test_cannot_sign_a_lease_twice_while_active(): void
    {
        $lease = $this->leaseFor(Application::STATUS_APPROVED);
        Contract::factory()->create(['lease_agreement_id' => $lease->id, 'signer_user_id' => $lease->customer_id]);

        $response = $this->actingAs($lease->customer, 'sanctum')->postJson('/api/customer/contracts', [
            'lease_agreement_id' => $lease->id,
            'signer_name' => 'Test Signer',
        ]);

        $response->assertStatus(422);
        $this->assertDatabaseCount('contracts', 1);
    }

    public function test_cannot_sign_another_customers_lease(): void
    {
        $lease = $this->leaseFor(Application::STATUS_APPROVED);
        $otherCustomer = User::factory()->create(['role' => User::ROLE_CUSTOMER]);

        $response = $this->actingAs($otherCustomer, 'sanctum')->postJson('/api/customer/contracts', [
            'lease_agreement_id' => $lease->id,
            'signer_name' => 'Not This Customer',
        ]);

        $response->assertStatus(404);
    }

    public function test_signed_lease_cannot_be_edited_until_voided(): void
    {
        $lease = $this->leaseFor(Application::STATUS_APPROVED);
        Contract::factory()->create(['lease_agreement_id' => $lease->id, 'signer_user_id' => $lease->customer_id]);
        $admin = User::factory()->create(['role' => User::ROLE_SUPER_ADMIN]);

        $blocked = $this->actingAs($admin, 'sanctum')->putJson("/api/admin/lease-agreements/{$lease->id}", [
            'term_months' => 48,
        ]);
        $blocked->assertStatus(422);

        $lease->contract->update(['voided_at' => now(), 'voided_by' => $admin->id, 'void_reason' => 'test']);

        $allowed = $this->actingAs($admin, 'sanctum')->putJson("/api/admin/lease-agreements/{$lease->id}", [
            'term_months' => 48,
        ]);
        $allowed->assertOk();
    }

    public function test_admin_void_requires_a_reason(): void
    {
        $lease = $this->leaseFor(Application::STATUS_APPROVED);
        $contract = Contract::factory()->create(['lease_agreement_id' => $lease->id, 'signer_user_id' => $lease->customer_id]);
        $admin = User::factory()->create(['role' => User::ROLE_SUPER_ADMIN]);

        $response = $this->actingAs($admin, 'sanctum')->postJson("/api/admin/contracts/{$contract->id}/void", []);

        $response->assertStatus(422);
        $this->assertDatabaseHas('contracts', ['id' => $contract->id, 'voided_at' => null]);
    }

    public function test_voiding_lets_the_customer_sign_again_with_an_incremented_version(): void
    {
        $lease = $this->leaseFor(Application::STATUS_APPROVED);
        $firstContract = Contract::factory()->create([
            'lease_agreement_id' => $lease->id,
            'signer_user_id' => $lease->customer_id,
            'version' => 1,
        ]);
        $admin = User::factory()->create(['role' => User::ROLE_SUPER_ADMIN]);

        $voidResponse = $this->actingAs($admin, 'sanctum')->postJson("/api/admin/contracts/{$firstContract->id}/void", [
            'reason' => 'Terms changed, needs a fresh signature.',
        ]);
        $voidResponse->assertOk();
        $this->assertDatabaseHas('contracts', ['id' => $firstContract->id, 'voided_by' => $admin->id]);

        // The lease's active-contract check should no longer see the voided one.
        $this->assertNull($lease->fresh()->contract);

        $signResponse = $this->actingAs($lease->customer, 'sanctum')->postJson('/api/customer/contracts', [
            'lease_agreement_id' => $lease->id,
            'signer_name' => 'Test Signer Round 2',
        ]);
        $signResponse->assertCreated();
        $signResponse->assertJsonPath('data.version', 2);

        // Full history keeps both rows — nothing was deleted.
        $this->assertDatabaseCount('contracts', 2);
    }

    public function test_regenerating_a_missing_pdf_uses_the_original_snapshot_not_current_data(): void
    {
        Storage::fake('local');

        $lease = $this->leaseFor(Application::STATUS_APPROVED);
        $contract = Contract::factory()->create([
            'lease_agreement_id' => $lease->id,
            'signer_user_id' => $lease->customer_id,
            'signer_name' => 'Original Signer',
        ]);

        ContractPdfService::ensure($contract);
        $originalHtml = $contract->fresh()->document_html;

        $this->assertNotEmpty($originalHtml);
        $this->assertStringContainsString('Original Signer', $originalHtml);

        // Simulate drift: the underlying lease data changes after signing,
        // and the stored PDF file is lost (e.g. storage issue).
        $lease->update(['monthly_rental_payment' => 999999]);
        Storage::disk('local')->delete($contract->fresh()->file_path);

        ContractPdfService::ensure($contract->fresh());

        // The regenerated document must still reflect what was captured at
        // signing time, not the changed data or a re-rendered template.
        $this->assertSame($originalHtml, $contract->fresh()->document_html);
        $this->assertStringNotContainsString('999,999', $contract->fresh()->document_html);
    }

    public function test_void_notification_is_skipped_when_the_customer_opted_out(): void
    {
        Notification::fake();

        $lease = $this->leaseFor(Application::STATUS_APPROVED);
        $lease->customer->customerProfile()->create(['status_change_emails' => false]);
        $contract = Contract::factory()->create(['lease_agreement_id' => $lease->id, 'signer_user_id' => $lease->customer_id]);
        $admin = User::factory()->create(['role' => User::ROLE_SUPER_ADMIN]);

        $this->actingAs($admin, 'sanctum')->postJson("/api/admin/contracts/{$contract->id}/void", [
            'reason' => 'Terms changed.',
        ])->assertOk();

        Notification::assertNothingSent();
    }

    public function test_void_notification_still_sent_by_default(): void
    {
        Notification::fake();

        $lease = $this->leaseFor(Application::STATUS_APPROVED);
        $contract = Contract::factory()->create(['lease_agreement_id' => $lease->id, 'signer_user_id' => $lease->customer_id]);
        $admin = User::factory()->create(['role' => User::ROLE_SUPER_ADMIN]);

        $this->actingAs($admin, 'sanctum')->postJson("/api/admin/contracts/{$contract->id}/void", [
            'reason' => 'Terms changed.',
        ])->assertOk();

        Notification::assertSentTo($lease->customer, ContractVoidedNotification::class);
    }
}
