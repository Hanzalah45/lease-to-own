<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Covers a gap found by this session's audit: Sanctum tokens never expire
 * and nothing re-checked status on later requests, so a suspended admin or
 * customer kept full API access with their existing token until they
 * happened to log out on their own. Suspending now revokes tokens immediately.
 */
class AccountSuspensionTest extends TestCase
{
    use RefreshDatabase;

    public function test_suspending_a_customer_revokes_their_existing_tokens(): void
    {
        $customer = User::factory()->create(['role' => User::ROLE_CUSTOMER, 'status' => 'active']);
        $customer->createToken('a');
        $customer->createToken('b');
        $superAdmin = User::factory()->create(['role' => User::ROLE_SUPER_ADMIN]);

        $this->assertSame(2, $customer->tokens()->count());

        $this->actingAs($superAdmin, 'sanctum')->putJson("/api/admin/customers/{$customer->id}", [
            'status' => 'suspended',
        ])->assertOk();

        $this->assertSame(0, $customer->tokens()->count());
    }

    public function test_suspending_an_admin_revokes_their_existing_tokens(): void
    {
        $admin = User::factory()->create(['role' => User::ROLE_ADMIN, 'status' => 'active']);
        $admin->createToken('a');
        $superAdmin = User::factory()->create(['role' => User::ROLE_SUPER_ADMIN]);

        $this->actingAs($superAdmin, 'sanctum')->putJson("/api/admin/admin-users/{$admin->id}", [
            'status' => 'suspended',
        ])->assertOk();

        $this->assertSame(0, $admin->tokens()->count());
    }

    public function test_reactivating_a_customer_does_not_touch_their_tokens(): void
    {
        $customer = User::factory()->create(['role' => User::ROLE_CUSTOMER, 'status' => 'active']);
        $customer->createToken('a');
        $superAdmin = User::factory()->create(['role' => User::ROLE_SUPER_ADMIN]);

        // A no-op status write (still "active") must not revoke a live session.
        $this->actingAs($superAdmin, 'sanctum')->putJson("/api/admin/customers/{$customer->id}", [
            'name' => $customer->name,
            'status' => 'active',
        ])->assertOk();

        $this->assertSame(1, $customer->tokens()->count());
    }
}
