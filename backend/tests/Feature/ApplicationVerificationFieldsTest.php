<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Covers the fields the client asked for on 2026-09-04: previous address,
 * two alternate contacts, employer position, and the rent/mortgage split
 * (mutually exclusive, driven by the raw "rent_" vs "own_" residence_type).
 */
class ApplicationVerificationFieldsTest extends TestCase
{
    use RefreshDatabase;

    private function basePayload(): array
    {
        return [
            'move_notification_agreed' => true,
            'cash_price' => 3000,
            'term_months' => 24,
            'monthly_rental' => 150,
            'alternate_contact_1_name' => 'Alt One',
            'alternate_contact_1_phone' => '555-100-0001',
            'alternate_contact_2_name' => 'Alt Two',
            'alternate_contact_2_phone' => '555-100-0002',
            'employer_name' => 'Acme Co',
            'employer_phone' => '555-200-1000',
            'employer_position' => 'Foreman',
            'previous_address' => '123 Old St',
        ];
    }

    public function test_renter_fields_are_saved_and_mortgage_fields_stay_null(): void
    {
        $customer = User::factory()->create(['role' => User::ROLE_CUSTOMER]);

        $response = $this->actingAs($customer, 'sanctum')->postJson('/api/customer/applications', array_merge($this->basePayload(), [
            'residence_type' => 'rent_apartment',
            'landlord_name' => 'Landlord Larry',
            'landlord_phone' => '555-300-2000',
            'monthly_rent' => 1200,
            'mortgage_amount' => 999999, // must be ignored — not an owner
        ]));

        $response->assertCreated();

        $profile = $customer->customerProfile()->first();
        $this->assertSame('Landlord Larry', $profile->landlord_name);
        $this->assertSame('555-300-2000', $profile->landlord_phone);
        $this->assertEquals(1200, $profile->monthly_rent);
        $this->assertNull($profile->mortgage_amount);
        $this->assertSame('Alt One', $profile->alternate_contact_1_name);
        $this->assertSame('Alt Two', $profile->alternate_contact_2_name);
        $this->assertSame('Foreman', $profile->employer_position);
        $this->assertSame('123 Old St', $profile->previous_address);
    }

    public function test_owner_fields_are_saved_and_landlord_fields_stay_null(): void
    {
        $customer = User::factory()->create(['role' => User::ROLE_CUSTOMER]);

        $response = $this->actingAs($customer, 'sanctum')->postJson('/api/customer/applications', array_merge($this->basePayload(), [
            'residence_type' => 'own_single',
            'mortgage_amount' => 1500,
            'mortgage_years' => '5',
            'landlord_name' => 'Should Be Ignored',
        ]));

        $response->assertCreated();

        $profile = $customer->customerProfile()->first();
        $this->assertEquals(1500, $profile->mortgage_amount);
        $this->assertSame('5', $profile->mortgage_years);
        $this->assertNull($profile->landlord_name);
        $this->assertNull($profile->monthly_rent);
    }
}
