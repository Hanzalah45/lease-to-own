<?php

namespace Tests\Feature;

use App\Models\ApplicationInfoRequest;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Covers gaps found by this session's audit of Customer\ApplicationController
 * and Admin\ApplicationController::store() — several fields the wizard makes
 * required or constrains to a fixed set were left wide open server-side, so
 * a direct API call could bypass those rules entirely.
 */
class ApplicationSubmissionValidationTest extends TestCase
{
    use RefreshDatabase;

    private function baseCustomerPayload(array $overrides = []): array
    {
        return array_merge([
            'cash_price' => 3000,
            'term_months' => 36,
            'monthly_rental' => 150,
            'move_notification_agreed' => true,
        ], $overrides);
    }

    public function test_move_notification_consent_is_required(): void
    {
        $customer = User::factory()->create(['role' => User::ROLE_CUSTOMER]);

        $response = $this->actingAs($customer, 'sanctum')->postJson('/api/customer/applications', $this->baseCustomerPayload([
            'move_notification_agreed' => false,
        ]));

        $response->assertStatus(422);
        $response->assertJsonValidationErrors('move_notification_agreed');
    }

    public function test_date_of_birth_cannot_be_in_the_future(): void
    {
        $customer = User::factory()->create(['role' => User::ROLE_CUSTOMER]);

        $response = $this->actingAs($customer, 'sanctum')->postJson('/api/customer/applications', $this->baseCustomerPayload([
            'date_of_birth' => now()->addYear()->toDateString(),
        ]));

        $response->assertStatus(422);
        $response->assertJsonValidationErrors('date_of_birth');
    }

    public function test_date_of_birth_cannot_be_implausibly_old(): void
    {
        $customer = User::factory()->create(['role' => User::ROLE_CUSTOMER]);

        $response = $this->actingAs($customer, 'sanctum')->postJson('/api/customer/applications', $this->baseCustomerPayload([
            'date_of_birth' => now()->subYears(150)->toDateString(),
        ]));

        $response->assertStatus(422);
        $response->assertJsonValidationErrors('date_of_birth');
    }

    public function test_a_reasonable_date_of_birth_is_accepted(): void
    {
        $customer = User::factory()->create(['role' => User::ROLE_CUSTOMER]);

        $response = $this->actingAs($customer, 'sanctum')->postJson('/api/customer/applications', $this->baseCustomerPayload([
            'date_of_birth' => now()->subYears(30)->toDateString(),
        ]));

        $response->assertCreated();
    }

    public function test_payment_due_day_must_be_a_day_of_the_month(): void
    {
        $customer = User::factory()->create(['role' => User::ROLE_CUSTOMER]);

        $response = $this->actingAs($customer, 'sanctum')->postJson('/api/customer/applications', $this->baseCustomerPayload([
            'payment_due_day' => 45,
        ]));

        $response->assertStatus(422);
        $response->assertJsonValidationErrors('payment_due_day');
    }

    public function test_residence_type_must_be_one_of_the_wizards_options(): void
    {
        $customer = User::factory()->create(['role' => User::ROLE_CUSTOMER]);

        $response = $this->actingAs($customer, 'sanctum')->postJson('/api/customer/applications', $this->baseCustomerPayload([
            'residence_type' => 'castle',
        ]));

        $response->assertStatus(422);
        $response->assertJsonValidationErrors('residence_type');
    }

    public function test_password_over_72_bytes_is_rejected_at_registration(): void
    {
        $response = $this->postJson('/api/auth/register', [
            'name' => 'Test User',
            'email' => 'longpw@example.com',
            'password' => str_repeat('a1', 40), // 80 chars
            'password_confirmation' => str_repeat('a1', 40),
        ]);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors('password');
    }

    public function test_a_second_needs_info_request_is_not_created_while_one_is_still_open(): void
    {
        $customer = User::factory()->create(['role' => User::ROLE_CUSTOMER]);
        $application = \App\Models\Application::factory()->create([
            'customer_id' => $customer->id,
            'status' => \App\Models\Application::STATUS_UNDER_REVIEW,
        ]);
        $admin = User::factory()->create(['role' => User::ROLE_SUPER_ADMIN]);

        $this->actingAs($admin, 'sanctum')->putJson("/api/admin/applications/{$application->id}", [
            'status' => 'needs_info',
            'status_notes' => 'Need your latest pay stub.',
        ])->assertOk();
        $this->assertSame(1, ApplicationInfoRequest::where('application_id', $application->id)->count());

        // Same admin (or a race) hits it again while the first request is
        // still open — must not create a second open request.
        $application->refresh();
        $this->actingAs($admin, 'sanctum')->putJson("/api/admin/applications/{$application->id}", [
            'status' => 'needs_info',
            'status_notes' => 'Also need your ID.',
        ]);

        $this->assertSame(1, ApplicationInfoRequest::where('application_id', $application->id)->count());
    }
}
