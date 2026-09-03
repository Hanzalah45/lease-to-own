<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * Covers two gaps found by this session's read-only audit of Milestone 1:
 * login responded differently for "no such email" vs "wrong password"
 * (enumeration), and email could be changed with no password re-confirmation
 * (a hijacked session could redirect the account, then take it over via
 * "forgot password" sent to the new address).
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

    public function test_changing_email_requires_current_password(): void
    {
        $user = User::factory()->create(['email' => 'old@example.com', 'password' => Hash::make('correct-password')]);

        $withoutPassword = $this->actingAs($user, 'sanctum')->putJson('/api/me', ['email' => 'new@example.com']);
        $withoutPassword->assertStatus(422);
        $this->assertSame('old@example.com', $user->fresh()->email);

        $withWrongPassword = $this->actingAs($user, 'sanctum')->putJson('/api/me', [
            'email' => 'new@example.com',
            'current_password' => 'not-the-password',
        ]);
        $withWrongPassword->assertStatus(422);

        $withCorrectPassword = $this->actingAs($user, 'sanctum')->putJson('/api/me', [
            'email' => 'new@example.com',
            'current_password' => 'correct-password',
        ]);
        $withCorrectPassword->assertOk();
        $this->assertSame('new@example.com', $user->fresh()->email);
    }

    public function test_name_only_edit_does_not_require_current_password(): void
    {
        $user = User::factory()->create(['name' => 'Old Name', 'email' => 'same@example.com']);

        $response = $this->actingAs($user, 'sanctum')->putJson('/api/me', [
            'name' => 'New Name',
            'email' => 'same@example.com',
        ]);

        $response->assertOk();
        $this->assertSame('New Name', $user->fresh()->name);
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
