<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Intuit's security requirements for QuickBooks apps (2026-11-17): pages that hold sensitive data answer with "no-cache" and
 * "no-store", not the framework default of "no-cache, private". Every API answer carries customer or payment data.
 */
class NoStoreResponsesTest extends TestCase
{
    use RefreshDatabase;

    public function test_api_answers_are_not_cached_or_stored(): void
    {
        $failedLogin = $this->postJson('/api/auth/login', ['email' => 'nobody@example.com', 'password' => 'whatever123']);
        $failedLogin->assertStatus(422);
        $this->assertNoStore($failedLogin->headers->get('Cache-Control'));

        $signedOut = $this->getJson('/api/auth/me');
        $signedOut->assertStatus(401);
        $this->assertNoStore($signedOut->headers->get('Cache-Control'));

        Sanctum::actingAs(User::factory()->create());
        $signedIn = $this->getJson('/api/auth/me');
        $signedIn->assertOk();
        $this->assertNoStore($signedIn->headers->get('Cache-Control'));
    }

    private function assertNoStore(?string $cacheControl): void
    {
        $this->assertNotNull($cacheControl);
        $this->assertStringContainsString('no-store', $cacheControl);
        $this->assertStringContainsString('no-cache', $cacheControl);
    }
}
