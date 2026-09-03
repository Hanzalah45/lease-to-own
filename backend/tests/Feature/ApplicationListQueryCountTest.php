<?php

namespace Tests\Feature;

use App\Models\Application;
use App\Models\LeaseAgreement;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Covers a gap found by this session's audit: Customer\ApplicationController's
 * present() unconditionally re-`load()`ed relations per row instead of using
 * loadMissing() against what index() already eager-loaded, and
 * LeaseAgreement::paymentsMadeCount() always issued a fresh COUNT query even
 * when its `payments` relation was already loaded — together turning one
 * list request into roughly 6 extra queries per application.
 */
class ApplicationListQueryCountTest extends TestCase
{
    use RefreshDatabase;

    public function test_listing_several_applications_does_not_scale_query_count_per_row(): void
    {
        $customer = User::factory()->create(['role' => User::ROLE_CUSTOMER]);

        for ($i = 0; $i < 5; $i++) {
            $application = Application::factory()->create(['customer_id' => $customer->id]);
            LeaseAgreement::factory()->create(['application_id' => $application->id, 'customer_id' => $customer->id]);
        }

        DB::enableQueryLog();
        $this->actingAs($customer, 'sanctum')->getJson('/api/customer/applications')->assertOk();
        $queryCount = count(DB::getQueryLog());
        DB::disableQueryLog();

        // A handful of fixed queries regardless of row count (not "N x rows")
        // proves this isn't re-querying relations per application anymore.
        $this->assertLessThan(10, $queryCount, "Expected a small, roughly constant query count for 5 applications, got {$queryCount}.");
    }
}
