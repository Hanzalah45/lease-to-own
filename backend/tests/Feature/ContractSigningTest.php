<?php

namespace Tests\Feature;

use App\Models\Application;
use App\Models\Contract;
use App\Models\LeaseAgreement;
use App\Models\User;
use App\Notifications\ContractVoidedNotification;
use App\Services\ContractPdfService;
use App\Services\ContractSigner;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
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
        $lease = $this->leaseFor(Application::STATUS_WAITING_REVIEW);

        $response = $this->actingAs($lease->customer, 'sanctum')->postJson('/api/customer/contracts', [
            'lease_agreement_id' => $lease->id,
            'signer_name' => 'Test Signer',
        ]);

        $response->assertStatus(422);
        $this->assertDatabaseCount('contracts', 0);
    }

    public function test_can_sign_once_application_is_approved(): void
    {
        $lease = $this->leaseFor(Application::STATUS_WAITING_DEPOSIT);

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

    public function test_a_new_signature_records_the_current_wording_of_the_agreement(): void
    {
        $lease = $this->leaseFor(Application::STATUS_WAITING_DEPOSIT);

        $this->actingAs($lease->customer, 'sanctum')->postJson('/api/customer/contracts', [
            'lease_agreement_id' => $lease->id,
            'signer_name' => 'Test Signer',
        ])->assertCreated();

        $this->assertDatabaseHas('contracts', [
            'lease_agreement_id' => $lease->id,
            'terms_version' => Contract::CURRENT_TERMS_VERSION,
        ]);
    }

    /** The early purchase price sentences of Section 3, with the line breaks of the template flattened. */
    private function sectionThree(string $html): string
    {
        $text = preg_replace('/\s+/', ' ', strip_tags($html));
        $start = strpos($text, '3. Rental-Purchase Ownership.');
        $end = strpos($text, '4. Maintenance, Repairs');

        return substr($text, $start, $end - $start);
    }

    public function test_the_early_purchase_price_deducts_additional_funds_on_the_current_wording_only(): void
    {
        Storage::fake('local');
        $lease = $this->leaseFor(Application::STATUS_WAITING_DEPOSIT);
        $make = fn (int $version) => Contract::factory()->create([
            'lease_agreement_id' => $lease->id,
            'signer_user_id' => $lease->customer_id,
            'signer_name' => 'Wording Signer',
            'terms_version' => $version,
        ]);

        $current = $make(Contract::CURRENT_TERMS_VERSION);
        ContractPdfService::ensure($current);
        $section = $this->sectionThree($current->fresh()->document_html);
        $this->assertStringContainsString('paid to date and less any additional funds (excludes taxes and fees)', $section);
        $this->assertStringContainsString('plus any Rental Payments still owed, less any additional funds.', $section);
        $this->assertStringNotContainsString('still owed and any additional funds', $section);

        // A contract signed on the original wording keeps it, even if its PDF has to be generated again later.
        $original = $make(1);
        ContractPdfService::ensure($original);
        $section = $this->sectionThree($original->fresh()->document_html);
        $this->assertStringContainsString('paid to date (excludes taxes and fees)', $section);
        $this->assertStringContainsString('plus any Rental Payments still owed and any additional funds.', $section);
        $this->assertStringNotContainsString('less any additional funds', $section);
    }

    public function test_additional_funds_go_to_repairs_before_any_refund_on_the_current_wording_only(): void
    {
        Storage::fake('local');
        $lease = $this->leaseFor(Application::STATUS_WAITING_DEPOSIT);
        $make = fn (int $version) => Contract::factory()->create([
            'lease_agreement_id' => $lease->id,
            'signer_user_id' => $lease->customer_id,
            'signer_name' => 'Repairs Signer',
            'terms_version' => $version,
        ]);
        $sectionNineteen = function (string $html): string {
            $text = preg_replace('/\s+/', ' ', strip_tags($html));
            $start = strpos($text, '19. Additional Funds Received.');
            $end = strpos($text, '20. Written Receipts.');

            return substr($text, $start, $end - $start);
        };
        $sentence = 'If the Property is returned in a condition that requires repairs or service in order to be re-sold, any Additional Funds will first be applied to the cost of repairs owed under this Agreement. Only any remaining balance of Additional Funds, if any, will be refundable to you.';

        $current = $make(Contract::CURRENT_TERMS_VERSION);
        ContractPdfService::ensure($current);
        $section = $sectionNineteen($current->fresh()->document_html);
        $this->assertStringContainsString('within a reasonable time. '.$sentence, $section);

        // A contract signed on the original wording does not gain the sentence.
        $original = $make(1);
        ContractPdfService::ensure($original);
        $this->assertStringNotContainsString('requires repairs or service', $sectionNineteen($original->fresh()->document_html));
    }

    public function test_cannot_sign_a_lease_twice_while_active(): void
    {
        $lease = $this->leaseFor(Application::STATUS_WAITING_DEPOSIT);
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
        $lease = $this->leaseFor(Application::STATUS_WAITING_DEPOSIT);
        $otherCustomer = User::factory()->create(['role' => User::ROLE_CUSTOMER]);

        $response = $this->actingAs($otherCustomer, 'sanctum')->postJson('/api/customer/contracts', [
            'lease_agreement_id' => $lease->id,
            'signer_name' => 'Not This Customer',
        ]);

        $response->assertStatus(404);
    }

    public function test_signed_lease_cannot_be_edited_until_voided(): void
    {
        $lease = $this->leaseFor(Application::STATUS_WAITING_DEPOSIT);
        Contract::factory()->create(['lease_agreement_id' => $lease->id, 'signer_user_id' => $lease->customer_id]);
        $admin = User::factory()->create(['role' => User::ROLE_SUPER_ADMIN]);

        $blocked = $this->actingAs($admin, 'sanctum')->putJson("/api/admin/lease-agreements/{$lease->id}", [
            'term_months' => 24,
        ]);
        $blocked->assertStatus(422);

        $lease->contract->update(['voided_at' => now(), 'voided_by' => $admin->id, 'void_reason' => 'test']);

        $allowed = $this->actingAs($admin, 'sanctum')->putJson("/api/admin/lease-agreements/{$lease->id}", [
            'term_months' => 24,
        ]);
        $allowed->assertOk();
    }

    public function test_admin_void_requires_a_reason(): void
    {
        $lease = $this->leaseFor(Application::STATUS_WAITING_DEPOSIT);
        $contract = Contract::factory()->create(['lease_agreement_id' => $lease->id, 'signer_user_id' => $lease->customer_id]);
        $admin = User::factory()->create(['role' => User::ROLE_SUPER_ADMIN]);

        $response = $this->actingAs($admin, 'sanctum')->postJson("/api/admin/contracts/{$contract->id}/void", []);

        $response->assertStatus(422);
        $this->assertDatabaseHas('contracts', ['id' => $contract->id, 'voided_at' => null]);
    }

    public function test_voiding_lets_the_customer_sign_again_with_an_incremented_version(): void
    {
        $lease = $this->leaseFor(Application::STATUS_WAITING_DEPOSIT);
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

        $lease = $this->leaseFor(Application::STATUS_WAITING_DEPOSIT);
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

        $lease = $this->leaseFor(Application::STATUS_WAITING_DEPOSIT);
        $lease->customer->customerProfile()->create(['status_change_emails' => false]);
        $contract = Contract::factory()->create(['lease_agreement_id' => $lease->id, 'signer_user_id' => $lease->customer_id]);
        $admin = User::factory()->create(['role' => User::ROLE_SUPER_ADMIN]);

        $this->actingAs($admin, 'sanctum')->postJson("/api/admin/contracts/{$contract->id}/void", [
            'reason' => 'Terms changed.',
            'notify_customer' => true,
        ])->assertOk();

        Notification::assertNothingSent();
    }

    public function test_voiding_does_not_email_the_customer_unless_staff_ask_for_it(): void
    {
        Notification::fake();

        $lease = $this->leaseFor(Application::STATUS_WAITING_DEPOSIT);
        $contract = Contract::factory()->create(['lease_agreement_id' => $lease->id, 'signer_user_id' => $lease->customer_id]);
        $admin = User::factory()->create(['role' => User::ROLE_SUPER_ADMIN]);

        // Client, Joel, 2026-10-14: a signature voided for a reason must not send an automatic "sign again" email.
        $this->actingAs($admin, 'sanctum')->postJson("/api/admin/contracts/{$contract->id}/void", [
            'reason' => 'Terms changed.',
        ])->assertOk();

        $this->assertNotNull($contract->fresh()->voided_at);
        Notification::assertNothingSent();
    }

    public function test_the_lease_list_tells_staff_which_leases_had_a_signature_voided(): void
    {
        $lease = $this->leaseFor(Application::STATUS_WAITING_DEPOSIT);
        $contract = Contract::factory()->create(['lease_agreement_id' => $lease->id, 'signer_user_id' => $lease->customer_id]);
        $admin = User::factory()->create(['role' => User::ROLE_SUPER_ADMIN]);
        $this->actingAs($admin, 'sanctum')->postJson("/api/admin/contracts/{$contract->id}/void", ['reason' => 'New terms.'])->assertOk();

        // Without an active contract the customer drops out of the signed list, so the list must say why (Contracts page).
        $row = collect($this->actingAs($admin, 'sanctum')->getJson('/api/admin/lease-agreements')->assertOk()->json('data'))
            ->firstWhere('id', $lease->id);

        $this->assertNull($row['contract']);
        $this->assertCount(1, $row['contracts']);
        $this->assertNotNull($row['contracts'][0]['voided_at']);
        $this->assertSame('New terms.', $row['contracts'][0]['void_reason']);
    }

    public function test_voiding_emails_the_customer_when_staff_choose_to_notify_them(): void
    {
        Notification::fake();

        $lease = $this->leaseFor(Application::STATUS_WAITING_DEPOSIT);
        $contract = Contract::factory()->create(['lease_agreement_id' => $lease->id, 'signer_user_id' => $lease->customer_id]);
        $admin = User::factory()->create(['role' => User::ROLE_SUPER_ADMIN]);

        $this->actingAs($admin, 'sanctum')->postJson("/api/admin/contracts/{$contract->id}/void", [
            'reason' => 'Terms changed.',
            'notify_customer' => true,
        ])->assertOk();

        Notification::assertSentTo($lease->customer, ContractVoidedNotification::class);
    }

    /**
     * Real bug found this session (2026-09-04): the "your contract was
     * signed" notification sent right after Contract::create() had no
     * try/catch — a live test against Mailtrap's sandbox hit its rate limit
     * mid-signature and the request 500'd, even though the signature had
     * already committed. The customer saw a scary SMTP error for a
     * successful signing. A transport failure here must not undo or hide
     * that the signature itself succeeded.
     */
    public function test_signing_succeeds_even_if_the_confirmation_email_fails_to_send(): void
    {
        Mail::shouldReceive('send')->andThrow(new \RuntimeException('SMTP transport failure (simulated)'));

        $lease = $this->leaseFor(Application::STATUS_WAITING_DEPOSIT);

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

    /**
     * Real gap found 2026-10-01 (client, Joel): the signing page only showed
     * a short summary before this, not the full agreement text — a customer
     * could agree to documents by name without ever being shown them.
     */
    public function test_customer_can_preview_the_full_agreement_before_signing(): void
    {
        $lease = $this->leaseFor(Application::STATUS_WAITING_DEPOSIT);

        $response = $this->actingAs($lease->customer, 'sanctum')
            ->get("/api/customer/lease-agreements/{$lease->id}/contract-preview");

        $response->assertOk();
        $response->assertHeader('content-type', 'application/pdf');
        $this->assertStringStartsWith('%PDF', $response->getContent());
    }

    public function test_customer_cannot_preview_another_customers_lease(): void
    {
        $lease = $this->leaseFor(Application::STATUS_WAITING_DEPOSIT);
        $otherCustomer = User::factory()->create(['role' => User::ROLE_CUSTOMER]);

        $response = $this->actingAs($otherCustomer, 'sanctum')
            ->get("/api/customer/lease-agreements/{$lease->id}/contract-preview");

        $response->assertStatus(404);
    }

    public function test_guest_can_preview_the_full_agreement_via_signed_link(): void
    {
        $lease = $this->leaseFor(Application::STATUS_WAITING_DEPOSIT);
        $url = ContractSigner::urlFor($lease->customer, $lease);
        parse_str(parse_url($url, PHP_URL_QUERY), $params);

        $response = $this->postJson('/api/contracts/verify-preview', $params);

        $response->assertOk();
        $response->assertHeader('content-type', 'application/pdf');
        $this->assertStringStartsWith('%PDF', $response->getContent());
    }

    public function test_a_tampered_preview_signature_is_rejected(): void
    {
        $lease = $this->leaseFor(Application::STATUS_WAITING_DEPOSIT);
        $url = ContractSigner::urlFor($lease->customer, $lease);
        parse_str(parse_url($url, PHP_URL_QUERY), $params);
        $params['signature'] = 'tampered';

        $response = $this->postJson('/api/contracts/verify-preview', $params);

        $response->assertStatus(422);
    }
}
