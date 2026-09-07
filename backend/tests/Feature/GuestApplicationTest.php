<?php

namespace Tests\Feature;

use App\Models\Application;
use App\Models\LeaseAgreement;
use App\Models\Payment;
use App\Models\User;
use App\Notifications\ActivateAccountNotification;
use App\Notifications\PaymentStatusChangedNotification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

/**
 * Covers the client's no-login application flow (2026-09-04): a prospective
 * customer applies from one generic public link before having an account —
 * a shadow User + Application are created with no equipment/lease yet — and
 * an admin later rejoins it with the normal lease-creation flow.
 */
class GuestApplicationTest extends TestCase
{
    use RefreshDatabase;

    private function basePayload(): array
    {
        return [
            'name' => 'Guest Applicant',
            'email' => 'guest@example.com',
            'move_notification_agreed' => true,
        ];
    }

    public function test_guest_can_submit_an_application_with_no_account(): void
    {
        $response = $this->postJson('/api/guest-applications', $this->basePayload());

        $response->assertCreated();

        $customer = User::where('email', 'guest@example.com')->first();
        $this->assertNotNull($customer);
        $this->assertSame(User::ROLE_CUSTOMER, $customer->role);
        $this->assertSame('pending', $customer->status);
        $this->assertNull($customer->email_verified_at);

        $application = Application::where('customer_id', $customer->id)->first();
        $this->assertNotNull($application);
        $this->assertSame(Application::STATUS_WAITING_REVIEW, $application->status);
        $this->assertNull($application->created_by);
        $this->assertNull($application->leaseAgreement);
    }

    public function test_guest_cannot_log_in_with_the_account_created_for_them(): void
    {
        $this->postJson('/api/guest-applications', $this->basePayload())->assertCreated();

        // No password was ever set for them — the account only becomes
        // usable once they set one via the Phase 6 pickup/first-payment flow.
        $response = $this->postJson('/api/auth/login', [
            'email' => 'guest@example.com',
            'password' => 'whatever-they-guess',
        ]);

        $response->assertStatus(422);
    }

    public function test_guest_email_must_be_unique(): void
    {
        User::factory()->create(['email' => 'guest@example.com']);

        $response = $this->postJson('/api/guest-applications', $this->basePayload());

        $response->assertStatus(422);
        $response->assertJsonValidationErrors('email');
    }

    public function test_apartment_residence_is_auto_declined_on_the_guest_path(): void
    {
        $response = $this->postJson('/api/guest-applications', array_merge($this->basePayload(), [
            'residence_type' => 'rent_apartment',
        ]));

        $response->assertCreated();

        $application = Application::where('customer_id', User::where('email', 'guest@example.com')->first()->id)->first();
        $this->assertSame(Application::STATUS_DECLINED, $application->status);
    }

    public function test_admin_can_attach_equipment_and_pricing_to_a_guest_application(): void
    {
        $admin = User::factory()->create(['role' => User::ROLE_ADMIN]);
        $application = Application::factory()->create();

        $response = $this->actingAs($admin, 'sanctum')->postJson("/api/admin/applications/{$application->id}/lease", [
            'make' => 'Worldlawn',
            'model' => 'Zero-Turn 52',
            'cash_price' => 5000,
            'term_months' => 36,
            'monthly_rental' => 200,
        ]);

        $response->assertOk();

        $lease = LeaseAgreement::where('application_id', $application->id)->first();
        $this->assertNotNull($lease);
        $this->assertEquals(5000, $lease->cash_price);
        $this->assertSame(36, $lease->term_months);
    }

    /**
     * The official divisor table from Outdoor Fix's own customer-facing
     * lease terms sheet (2026-09-04): "Divide the cash price (excluding
     * tax) by 19.8 for 36-months, 16.0 for 24-months, or 10.0 for
     * 12-months." Not proportional to term — a lookup, not a formula. The
     * sheet's own worked example: $4,000 / 19.8 = $202.02 for 36 months.
     * monthly_rental posted here is ignored — computed server-side.
     */
    public function test_monthly_rental_uses_the_official_divisor_per_term(): void
    {
        $admin = User::factory()->create(['role' => User::ROLE_ADMIN]);

        $cases = [
            12 => 400.00,   // 4000 / 10.0
            24 => 250.00,   // 4000 / 16.0
            36 => 202.02,   // 4000 / 19.8, the sheet's own worked example
        ];

        foreach ($cases as $term => $expectedMonthly) {
            $application = Application::factory()->create();

            $this->actingAs($admin, 'sanctum')->postJson("/api/admin/applications/{$application->id}/lease", [
                'make' => 'Worldlawn',
                'model' => 'Zero-Turn 52',
                'cash_price' => 4000,
                'term_months' => $term,
                'monthly_rental' => 999999, // must be ignored, not trusted from the client
            ])->assertOk();

            $lease = LeaseAgreement::where('application_id', $application->id)->first();
            $this->assertEquals($expectedMonthly, (float) $lease->monthly_rental_payment, "term={$term}");
        }
    }

    /**
     * The client's official pricing blueprint (2026-09-04), verified against
     * its own worked example (Cash Price $4,899, 36 months):
     *   Base monthly = 4899 / 19.8 = 247.42
     *   With LDW: +0.75%/mo (36.74) -> monthly 284.16; deposit = 7% (342.93)
     *   No LDW:   +0.35%/mo surcharge (17.15) -> monthly 264.57;
     *             deposit = 3x that monthly (793.71)
     * The $150 tracking device fee is a separate line item, not folded into
     * the deposit (checked wherever "total due today" is computed, not
     * here). Submitted monthly_rental/security_deposit are both ignored.
     */
    public function test_pricing_matches_the_official_blueprints_worked_example_with_ldw(): void
    {
        $admin = User::factory()->create(['role' => User::ROLE_ADMIN]);
        $application = Application::factory()->create();

        $this->actingAs($admin, 'sanctum')->postJson("/api/admin/applications/{$application->id}/lease", [
            'make' => 'Worldlawn',
            'model' => 'Zero-Turn 52',
            'cash_price' => 4899,
            'term_months' => 36,
            'monthly_rental' => 999999, // must be ignored
            'ldw' => 'yes',
            'security_deposit' => 999999, // must be ignored
        ])->assertOk();

        $lease = LeaseAgreement::where('application_id', $application->id)->first();
        $this->assertEquals(247.42, (float) $lease->monthly_rental_payment);
        $this->assertEquals(36.74, (float) $lease->ldw_amount);
        $this->assertEquals(342.93, (float) $lease->security_deposit);
        $this->assertEquals(284.16, $lease->totalMonthlyPayment() - $lease->salesTaxAmount());
    }

    public function test_pricing_matches_the_official_blueprints_worked_example_without_ldw(): void
    {
        $admin = User::factory()->create(['role' => User::ROLE_ADMIN]);
        $application = Application::factory()->create();

        $this->actingAs($admin, 'sanctum')->postJson("/api/admin/applications/{$application->id}/lease", [
            'make' => 'Worldlawn',
            'model' => 'Zero-Turn 52',
            'cash_price' => 4899,
            'term_months' => 36,
            'monthly_rental' => 999999, // must be ignored
            'ldw' => 'no',
        ])->assertOk();

        $lease = LeaseAgreement::where('application_id', $application->id)->first();
        $this->assertEquals(247.42, (float) $lease->monthly_rental_payment);
        $this->assertEquals(17.15, (float) $lease->ldw_amount); // the no-LDW surcharge, same column
        $this->assertEquals(793.71, (float) $lease->security_deposit);
        $this->assertEquals(264.57, $lease->totalMonthlyPayment() - $lease->salesTaxAmount());
    }

    public function test_an_unsupported_lease_term_is_rejected(): void
    {
        $admin = User::factory()->create(['role' => User::ROLE_ADMIN]);
        $application = Application::factory()->create();

        $response = $this->actingAs($admin, 'sanctum')->postJson("/api/admin/applications/{$application->id}/lease", [
            'make' => 'Worldlawn',
            'model' => 'Zero-Turn 52',
            'cash_price' => 4000,
            'term_months' => 48,
            'monthly_rental' => 200,
        ]);

        $response->assertStatus(422);
        $this->assertDatabaseCount('lease_agreements', 0);
    }

    public function test_attaching_a_lease_twice_is_rejected(): void
    {
        $admin = User::factory()->create(['role' => User::ROLE_ADMIN]);
        $application = Application::factory()->create();

        $payload = [
            'make' => 'Worldlawn',
            'model' => 'Zero-Turn 52',
            'cash_price' => 5000,
            'term_months' => 36,
            'monthly_rental' => 200,
        ];

        $this->actingAs($admin, 'sanctum')->postJson("/api/admin/applications/{$application->id}/lease", $payload)->assertOk();
        $response = $this->actingAs($admin, 'sanctum')->postJson("/api/admin/applications/{$application->id}/lease", $payload);

        $response->assertStatus(422);
    }

    /**
     * Regression test for a gap found by live-testing this session: "Mark
     * Delivered & Paid" (waiting_delivery -> finished) only relabeled the
     * application's status — it never actually recorded the lease's first
     * payment or activated the guest's account, silently breaking Phase 6
     * for every application that reaches "finished" through this button
     * rather than through the Payments page directly.
     */
    public function test_marking_a_guest_application_finished_pays_the_first_payment_and_activates_the_account(): void
    {
        Notification::fake();

        $admin = User::factory()->create(['role' => User::ROLE_ADMIN]);

        $this->postJson('/api/guest-applications', $this->basePayload())->assertCreated();
        $customer = User::where('email', 'guest@example.com')->first();
        $application = Application::where('customer_id', $customer->id)->first();

        $this->actingAs($admin, 'sanctum')->postJson("/api/admin/applications/{$application->id}/lease", [
            'make' => 'Worldlawn',
            'model' => 'Zero-Turn 52',
            'cash_price' => 5000,
            'term_months' => 36,
            'monthly_rental' => 200,
        ])->assertOk();

        $path = [
            Application::STATUS_WAITING_APPROVAL,
            Application::STATUS_IN_VERIFICATION,
            Application::STATUS_WAITING_DEPOSIT,
        ];
        foreach ($path as $status) {
            $this->actingAs($admin, 'sanctum')
                ->putJson("/api/admin/applications/{$application->id}", ['status' => $status])
                ->assertOk();
        }

        // "Waiting on delivery" now requires a signed contract (real gap
        // found and fixed this session) — sign via the guest signed link,
        // same as a real guest customer would (no usable password yet).
        $lease = LeaseAgreement::where('application_id', $application->id)->first();
        $signUrl = \App\Services\ContractSigner::urlFor($customer, $lease);
        parse_str(parse_url($signUrl, PHP_URL_QUERY), $signParams);
        $this->postJson('/api/contracts/verify-sign', [...$signParams, 'signer_name' => 'Guest Applicant'])
            ->assertCreated();

        foreach ([Application::STATUS_WAITING_DELIVERY, Application::STATUS_FINISHED] as $status) {
            $this->actingAs($admin, 'sanctum')
                ->putJson("/api/admin/applications/{$application->id}", ['status' => $status])
                ->assertOk();
        }

        $lease = $lease->fresh();
        $firstPayment = $lease->payments()->orderBy('due_date')->first();
        $this->assertSame(Payment::STATUS_PAID, $firstPayment->status);
        $this->assertNotNull($firstPayment->paid_date);

        Notification::assertSentTo($customer, ActivateAccountNotification::class);
        Notification::assertSentTo($admin, PaymentStatusChangedNotification::class);
    }
}
