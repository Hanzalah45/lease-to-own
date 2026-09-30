<?php

namespace Tests\Feature;

use App\Models\QuickbooksConnection;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Covers the QuickBooks OAuth connection scaffolding — genuinely independent
 * of the still-open Stripe architecture questions and of real API keys
 * (config('services.quickbooks.*') is set to fake values below; Intuit's own
 * HTTP calls are faked the same way Plaid's are elsewhere, see
 * RiskVerificationActionsTest). This only covers connecting the company's
 * single QuickBooks account — syncing actual records to it is separate,
 * later work.
 */
class QuickbooksConnectionTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config([
            'services.quickbooks.client_id' => 'fake-client-id',
            'services.quickbooks.client_secret' => 'fake-client-secret',
            'services.quickbooks.redirect_uri' => 'http://localhost:8010/api/quickbooks/callback',
            'app.frontend_url' => 'http://localhost:3000',
        ]);
    }

    private function superAdmin(): User
    {
        return User::factory()->create(['role' => User::ROLE_SUPER_ADMIN]);
    }

    public function test_status_reports_not_connected_when_no_connection_exists(): void
    {
        $response = $this->actingAs($this->superAdmin(), 'sanctum')->getJson('/api/admin/quickbooks/status');

        $response->assertOk();
        $response->assertJson(['data' => ['connected' => false]]);
    }

    public function test_a_regular_admin_cannot_reach_any_quickbooks_route(): void
    {
        $admin = User::factory()->create(['role' => User::ROLE_ADMIN]);

        $this->actingAs($admin, 'sanctum')->getJson('/api/admin/quickbooks/status')->assertForbidden();
        $this->actingAs($admin, 'sanctum')->postJson('/api/admin/quickbooks/connect')->assertForbidden();
        $this->actingAs($admin, 'sanctum')->deleteJson('/api/admin/quickbooks/disconnect')->assertForbidden();
    }

    public function test_connect_returns_an_authorize_url_and_caches_the_state(): void
    {
        $admin = $this->superAdmin();

        $response = $this->actingAs($admin, 'sanctum')->postJson('/api/admin/quickbooks/connect');

        $response->assertOk();
        $url = $response->json('data.url');
        $this->assertStringStartsWith('https://appcenter.intuit.com/connect/oauth2?', $url);
        $this->assertStringContainsString('client_id=fake-client-id', $url);
        $this->assertStringContainsString('scope=com.intuit.quickbooks.accounting', $url);

        parse_str(parse_url($url, PHP_URL_QUERY), $params);
        $this->assertSame($admin->id, Cache::get("quickbooks_oauth_state:{$params['state']}"));
    }

    public function test_callback_with_a_valid_state_exchanges_the_code_and_stores_the_connection(): void
    {
        $admin = $this->superAdmin();
        Cache::put('quickbooks_oauth_state:valid-state', $admin->id, now()->addMinutes(10));

        Http::fake([
            'oauth.platform.intuit.com/*' => Http::response([
                'access_token' => 'fake-access-token',
                'refresh_token' => 'fake-refresh-token',
                'expires_in' => 3600,
                'x_refresh_token_expires_in' => 8726400,
            ], 200),
        ]);

        $response = $this->get('/api/quickbooks/callback?'.http_build_query([
            'code' => 'fake-auth-code',
            'realmId' => '123456789',
            'state' => 'valid-state',
        ]));

        $response->assertRedirect('http://localhost:3000/admin/settings?quickbooks=connected');

        $connection = QuickbooksConnection::first();
        $this->assertNotNull($connection);
        $this->assertSame('123456789', $connection->realm_id);
        $this->assertSame('fake-access-token', $connection->access_token);
        $this->assertSame('fake-refresh-token', $connection->refresh_token);
        $this->assertSame($admin->id, $connection->connected_by);

        // The state can't be replayed against a second callback.
        $this->assertNull(Cache::get('quickbooks_oauth_state:valid-state'));
    }

    public function test_callback_with_an_unknown_state_is_rejected_and_stores_nothing(): void
    {
        Http::fake();

        $response = $this->get('/api/quickbooks/callback?'.http_build_query([
            'code' => 'fake-auth-code',
            'realmId' => '123456789',
            'state' => 'never-issued',
        ]));

        $response->assertRedirect('http://localhost:3000/admin/settings?quickbooks=invalid_state');
        $this->assertNull(QuickbooksConnection::first());
        Http::assertNothingSent();
    }

    public function test_callback_when_the_admin_denies_access_is_handled_without_calling_intuit(): void
    {
        $admin = $this->superAdmin();
        Cache::put('quickbooks_oauth_state:denied-state', $admin->id, now()->addMinutes(10));
        Http::fake();

        $response = $this->get('/api/quickbooks/callback?'.http_build_query([
            'error' => 'access_denied',
            'state' => 'denied-state',
        ]));

        $response->assertRedirect('http://localhost:3000/admin/settings?quickbooks=denied');
        $this->assertNull(QuickbooksConnection::first());
        Http::assertNothingSent();
    }

    public function test_status_reflects_an_existing_connection_without_exposing_tokens(): void
    {
        $admin = $this->superAdmin();
        QuickbooksConnection::create([
            'realm_id' => '999',
            'access_token' => 'secret-access',
            'access_token_expires_at' => now()->addHour(),
            'refresh_token' => 'secret-refresh',
            'refresh_token_expires_at' => now()->addDays(100),
            'connected_by' => $admin->id,
        ]);

        $response = $this->actingAs($admin, 'sanctum')->getJson('/api/admin/quickbooks/status');

        $response->assertOk();
        $response->assertJsonPath('data.connected', true);
        $response->assertJsonPath('data.realm_id', '999');
        $response->assertJsonPath('data.connected_by', $admin->name);
        $response->assertJsonMissingPath('data.access_token');
        $response->assertJsonMissingPath('data.refresh_token');
    }

    public function test_disconnect_revokes_and_removes_the_connection(): void
    {
        $admin = $this->superAdmin();
        QuickbooksConnection::create([
            'realm_id' => '999',
            'access_token' => 'secret-access',
            'access_token_expires_at' => now()->addHour(),
            'refresh_token' => 'secret-refresh',
            'refresh_token_expires_at' => now()->addDays(100),
            'connected_by' => $admin->id,
        ]);

        Http::fake([
            'developer.api.intuit.com/*' => Http::response('', 200),
        ]);

        $response = $this->actingAs($admin, 'sanctum')->deleteJson('/api/admin/quickbooks/disconnect');

        $response->assertOk();
        $this->assertNull(QuickbooksConnection::first());
        Http::assertSent(fn ($request) => str_contains($request->url(), 'tokens/revoke'));
    }
}
