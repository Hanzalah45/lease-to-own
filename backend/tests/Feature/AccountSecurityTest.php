<?php

namespace Tests\Feature;

use App\Models\User;
use App\Notifications\AccountSecurityUpdatedNotification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

/**
 * Covers gaps found by this session's read-only audit of Milestone 1 (login
 * responded differently for "no such email" vs "wrong password" —
 * enumeration — and password changes needed re-confirmation to stop a
 * hijacked session locking the real owner out), plus the later client
 * decision (2026-09-04) to disable self-service email changes entirely
 * rather than add re-verification for them.
 */
class AccountSecurityTest extends TestCase
{
    use RefreshDatabase;

    public function test_login_gives_the_same_error_for_unknown_email_and_wrong_password(): void
    {
        User::factory()->create(['email' => 'known@example.com', 'password' => Hash::make('correct-password')]);

        $unknownEmail = $this->postJson('/api/auth/login', [
            'email' => 'nobody@example.com',
            'password' => 'whatever123',
        ]);
        $wrongPassword = $this->postJson('/api/auth/login', [
            'email' => 'known@example.com',
            'password' => 'wrong-password',
        ]);

        $unknownEmail->assertStatus(422);
        $wrongPassword->assertStatus(422);
        $this->assertSame($unknownEmail->json('errors'), $wrongPassword->json('errors'));
    }

    public function test_email_cannot_be_changed_through_self_service(): void
    {
        // Client decision, 2026-09-04: self-service email changes are
        // disabled account-wide — a customer or admin who needs their email
        // updated goes through an admin (Admin\CustomerController still
        // allows that, with the customer notified). `email` isn't in
        // ProfileController's validation rules at all, so a posted value is
        // silently ignored rather than erroring, same as any other unknown field.
        $user = User::factory()->create(['email' => 'old@example.com', 'password' => Hash::make('correct-password')]);

        $response = $this->actingAs($user, 'sanctum')->putJson('/api/me', [
            'email' => 'new@example.com',
            'current_password' => 'correct-password',
        ]);

        $response->assertOk();
        $this->assertSame('old@example.com', $user->fresh()->email);
    }

    public function test_name_only_edit_does_not_require_current_password(): void
    {
        $user = User::factory()->create(['name' => 'Old Name', 'email' => 'same@example.com']);

        $response = $this->actingAs($user, 'sanctum')->putJson('/api/me', [
            'name' => 'New Name',
        ]);

        $response->assertOk();
        $this->assertSame('New Name', $user->fresh()->name);
    }

    public function test_editing_name_or_phone_notifies_the_customer_not_just_email_or_password_changes(): void
    {
        Notification::fake();

        $user = User::factory()->create(['name' => 'Old Name', 'phone' => '555-0000']);

        $this->actingAs($user, 'sanctum')->putJson('/api/me', ['name' => 'New Name'])->assertOk();

        Notification::assertSentTo($user, AccountSecurityUpdatedNotification::class);
    }

    public function test_login_is_rate_limited(): void
    {
        User::factory()->create(['email' => 'known@example.com', 'password' => Hash::make('correct-password')]);

        for ($i = 0; $i < 10; $i++) {
            $this->postJson('/api/auth/login', ['email' => 'known@example.com', 'password' => 'wrong']);
        }

        $response = $this->postJson('/api/auth/login', ['email' => 'known@example.com', 'password' => 'wrong']);
        $response->assertStatus(429);
    }
}
