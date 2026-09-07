<?php

namespace Tests\Feature;

use App\Models\User;
use App\Notifications\VerifyEmailNotification;
use App\Services\EmailVerificationSigner;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

/**
 * Covers this session's registration-verification gate: a new customer
 * stays "pending" (LoginController already rejects any non-active status)
 * until they click the emailed signed link.
 */
class EmailVerificationTest extends TestCase
{
    use RefreshDatabase;

    public function test_registration_creates_a_pending_account_with_no_token_and_sends_a_verification_link(): void
    {
        Notification::fake();

        $response = $this->postJson('/api/auth/register', [
            'name' => 'Test Customer',
            'email' => 'newcustomer@example.com',
            'password' => 'password123',
            'password_confirmation' => 'password123',
        ]);

        $response->assertCreated();
        $response->assertJsonMissingPath('token');

        $user = User::where('email', 'newcustomer@example.com')->firstOrFail();
        $this->assertSame('pending', $user->status);
        $this->assertNull($user->email_verified_at);

        Notification::assertSentTo($user, VerifyEmailNotification::class);
    }

    public function test_an_unverified_account_cannot_log_in(): void
    {
        $user = User::factory()->unverified()->create([
            'role' => User::ROLE_CUSTOMER,
            'status' => 'pending',
            'password' => bcrypt('password123'),
        ]);

        $response = $this->postJson('/api/auth/login', [
            'email' => $user->email,
            'password' => 'password123',
        ]);

        $response->assertStatus(403);
        $response->assertJson(['message' => 'Please verify your email before logging in.']);
    }

    public function test_a_valid_signed_link_activates_the_account_and_login_then_succeeds(): void
    {
        $user = User::factory()->unverified()->create([
            'role' => User::ROLE_CUSTOMER,
            'status' => 'pending',
            'password' => bcrypt('password123'),
        ]);

        $url = EmailVerificationSigner::urlFor($user);
        parse_str((string) parse_url($url, PHP_URL_QUERY), $params);

        $verify = $this->postJson('/api/auth/email/verify', $params);
        $verify->assertOk();

        $user->refresh();
        $this->assertSame('active', $user->status);
        $this->assertNotNull($user->email_verified_at);

        $login = $this->postJson('/api/auth/login', [
            'email' => $user->email,
            'password' => 'password123',
        ]);
        $login->assertOk();
        $login->assertJsonStructure(['user', 'token']);
    }

    public function test_a_tampered_signature_is_rejected(): void
    {
        $user = User::factory()->unverified()->create(['role' => User::ROLE_CUSTOMER, 'status' => 'pending']);

        $url = EmailVerificationSigner::urlFor($user);
        parse_str((string) parse_url($url, PHP_URL_QUERY), $params);
        $params['signature'] = 'not-the-real-signature';

        $response = $this->postJson('/api/auth/email/verify', $params);

        $response->assertStatus(422);
        $this->assertNull($user->fresh()->email_verified_at);
    }

    public function test_resend_sends_a_fresh_link_for_an_unverified_email_and_stays_silent_for_an_unknown_one(): void
    {
        Notification::fake();

        $user = User::factory()->unverified()->create(['role' => User::ROLE_CUSTOMER, 'status' => 'pending']);

        $known = $this->postJson('/api/auth/email/resend', ['email' => $user->email]);
        $unknown = $this->postJson('/api/auth/email/resend', ['email' => 'nobody@example.com']);

        $known->assertOk();
        $unknown->assertOk();
        $this->assertSame($known->json('message'), $unknown->json('message'));

        Notification::assertSentTo($user, VerifyEmailNotification::class);
    }
}
