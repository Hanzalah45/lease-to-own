<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Covers a gap found by this session's audit: a 200 response from Plaid
 * missing an expected field (link_token / access_token / item_id / accounts)
 * used to violate PlaidClient's non-nullable return types and throw an
 * uncaught TypeError — surfacing as a generic 500 instead of the friendly
 * 502 message these endpoints already handle for real Plaid failures.
 */
class PlaidMalformedResponseTest extends TestCase
{
    use RefreshDatabase;

    public function test_link_token_endpoint_handles_a_malformed_200_gracefully(): void
    {
        Http::fake(['sandbox.plaid.com/*' => Http::response(['unexpected' => 'shape'], 200)]);

        $customer = User::factory()->create(['role' => User::ROLE_CUSTOMER]);

        $response = $this->actingAs($customer, 'sanctum')->postJson('/api/customer/plaid/link-token');

        $response->assertStatus(502);
        $response->assertJson(['message' => 'Could not start bank verification. Please try again.']);
    }

    public function test_exchange_endpoint_handles_a_malformed_200_gracefully(): void
    {
        Http::fake(['sandbox.plaid.com/*' => Http::response(['unexpected' => 'shape'], 200)]);

        $customer = User::factory()->create(['role' => User::ROLE_CUSTOMER]);

        $response = $this->actingAs($customer, 'sanctum')->postJson('/api/customer/plaid/exchange', [
            'public_token' => 'public-sandbox-fake-token',
        ]);

        $response->assertStatus(502);
        $response->assertJson(['message' => 'Could not verify your bank connection. Please try again.']);
    }
}
