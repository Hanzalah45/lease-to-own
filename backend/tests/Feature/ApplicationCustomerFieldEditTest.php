<?php

namespace Tests\Feature;

use App\Models\Application;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Real gaps found live 2026-09-16, all in the application detail page's
 * "Edit Customer" modal: (1) date of birth was entered wrong at application
 * time and there was no way for an admin to correct it — CUSTOMER_FIELDS
 * didn't offer it, and this endpoint didn't accept it either; (2) the
 * frontend's saveCustomer() only ever sent 5 of CUSTOMER_FIELDS' ~18 keys,
 * so editing e.g. a landlord or employer field silently did nothing even
 * though this endpoint already accepted them; (3) residence_type's own
 * validation rule checked against the wizard's raw pre-mapping values
 * (rent_house, own_single, ...), but this endpoint writes straight into
 * customer_profiles.residence_type, which only ever holds
 * RiskScoringService::mapResidenceType()'s coarser output (house/apartment/
 * other) — so the rule rejected 2 of the edit form's 3 dropdown options and
 * blocked saving ANY customer field whenever residence_type held its
 * default, discovered while reproducing gap (1) in the browser.
 */
class ApplicationCustomerFieldEditTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_can_correct_a_customers_date_of_birth(): void
    {
        $admin = User::factory()->create(['role' => User::ROLE_ADMIN]);
        $application = Application::factory()->create();

        $response = $this->actingAs($admin, 'sanctum')->putJson("/api/admin/applications/{$application->id}", [
            'customer' => ['date_of_birth' => '1990-05-15'],
        ]);

        $response->assertOk();
        $this->assertSame('1990-05-15', $application->customer->customerProfile()->first()->date_of_birth->toDateString());
    }

    public function test_a_future_date_of_birth_is_rejected(): void
    {
        $admin = User::factory()->create(['role' => User::ROLE_ADMIN]);
        $application = Application::factory()->create();

        $response = $this->actingAs($admin, 'sanctum')->putJson("/api/admin/applications/{$application->id}", [
            'customer' => ['date_of_birth' => now()->addDay()->toDateString()],
        ]);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors('customer.date_of_birth');
    }

    public function test_an_implausibly_old_date_of_birth_is_rejected(): void
    {
        $admin = User::factory()->create(['role' => User::ROLE_ADMIN]);
        $application = Application::factory()->create();

        $response = $this->actingAs($admin, 'sanctum')->putJson("/api/admin/applications/{$application->id}", [
            'customer' => ['date_of_birth' => '1850-01-01'],
        ]);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors('customer.date_of_birth');
    }

    public function test_every_customer_field_the_frontend_edit_modal_offers_is_actually_accepted(): void
    {
        $admin = User::factory()->create(['role' => User::ROLE_ADMIN]);
        $application = Application::factory()->create();

        $payload = [
            'address_line_1' => '123 Main St',
            'city' => 'Willis',
            'state' => 'TX',
            'zip' => '77378',
            'date_of_birth' => '1985-03-20',
            'residence_type' => 'house',
            'previous_address' => '456 Old Rd',
            'landlord_name' => 'Landlord Larry',
            'landlord_phone' => '555-300-2000',
            'monthly_rent' => 1200,
            'employer_name' => 'Acme Co',
            'employer_phone' => '555-200-1000',
            'employer_position' => 'Foreman',
            'alternate_contact_1_name' => 'Alt One',
            'alternate_contact_1_phone' => '555-100-0001',
            'alternate_contact_2_name' => 'Alt Two',
            'alternate_contact_2_phone' => '555-100-0002',
        ];

        $this->actingAs($admin, 'sanctum')->putJson("/api/admin/applications/{$application->id}", ['customer' => $payload])->assertOk();

        $profile = $application->customer->customerProfile()->first();
        $this->assertSame('123 Main St', $profile->address_line_1);
        $this->assertSame('1985-03-20', $profile->date_of_birth->toDateString());
        $this->assertSame('Landlord Larry', $profile->landlord_name);
        $this->assertSame('555-300-2000', $profile->landlord_phone);
        $this->assertEquals(1200, $profile->monthly_rent);
        $this->assertSame('Acme Co', $profile->employer_name);
        $this->assertSame('Foreman', $profile->employer_position);
        $this->assertSame('Alt One', $profile->alternate_contact_1_name);
        $this->assertSame('Alt Two', $profile->alternate_contact_2_name);
        $this->assertSame('456 Old Rd', $profile->previous_address);
        $this->assertSame('house', $profile->residence_type);
    }

    public function test_residence_type_accepts_the_edit_forms_mapped_options(): void
    {
        $admin = User::factory()->create(['role' => User::ROLE_ADMIN]);

        foreach (['house', 'apartment', 'other'] as $value) {
            $application = Application::factory()->create();

            $this->actingAs($admin, 'sanctum')
                ->putJson("/api/admin/applications/{$application->id}", ['customer' => ['residence_type' => $value]])
                ->assertOk();

            $this->assertSame($value, $application->customer->customerProfile()->first()->residence_type);
        }
    }

    public function test_residence_type_rejects_the_wizards_raw_pre_mapping_values(): void
    {
        $admin = User::factory()->create(['role' => User::ROLE_ADMIN]);

        foreach (['rent_house', 'rent_apartment', 'own_single', 'own_multi'] as $rawWizardValue) {
            $application = Application::factory()->create();

            $response = $this->actingAs($admin, 'sanctum')
                ->putJson("/api/admin/applications/{$application->id}", ['customer' => ['residence_type' => $rawWizardValue]]);

            $response->assertStatus(422);
            $response->assertJsonValidationErrors('customer.residence_type');
        }
    }
}
