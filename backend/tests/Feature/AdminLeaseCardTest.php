<?php

namespace Tests\Feature;

use App\Models\AdminPermission;
use App\Models\Application;
use App\Models\LeaseAgreement;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Stripe\ApiRequestor;
use Tests\Support\FakeStripeHttpClient;
use Tests\TestCase;

/**
 * Staff add the customer's card to their AutoPay (client, Joel, 2026-10-14): a
 * customer who could not manage the card form was helped by phone and Joel
 * wanted to enter it himself as an admin.
 */
class AdminLeaseCardTest extends TestCase
{
    use RefreshDatabase;

    private FakeStripeHttpClient $stripe;

    protected function setUp(): void
    {
        parent::setUp();
        $this->stripe = new FakeStripeHttpClient;
        ApiRequestor::setHttpClient($this->stripe);
    }

    protected function tearDown(): void
    {
        ApiRequestor::setHttpClient(null);
        parent::tearDown();
    }

    private function lease(array $leaseOverrides = [], string $status = Application::STATUS_WAITING_DELIVERY): LeaseAgreement
    {
        $customer = User::factory()->create(['role' => User::ROLE_CUSTOMER]);
        $customer->customerProfile()->create(['stripe_customer_id' => 'cus_card']);
        $application = Application::factory()->create(['customer_id' => $customer->id, 'status' => $status]);

        return LeaseAgreement::factory()->create(array_merge([
            'application_id' => $application->id,
            'customer_id' => $customer->id,
            'equipment_unit_id' => null,
            'autopay_enabled' => true,
            'stripe_card_payment_method_id' => null,
            'stripe_bank_payment_method_id' => null,
        ], $leaseOverrides));
    }

    private function admin(): User
    {
        return User::factory()->create(['role' => User::ROLE_SUPER_ADMIN]);
    }

    private function queueSucceededCard(string $customer = 'cus_card'): void
    {
        $this->stripe->queue(['id' => 'seti_ok', 'object' => 'setup_intent', 'status' => 'succeeded', 'customer' => $customer, 'payment_method' => 'pm_new_card']);
        $this->stripe->queue(['id' => 'pm_new_card', 'object' => 'payment_method', 'type' => 'card', 'card' => ['brand' => 'visa', 'last4' => '4242']]);
    }

    private function save(User $admin, LeaseAgreement $lease, array $body = [])
    {
        return $this->actingAs($admin, 'sanctum')->postJson("/api/admin/lease-agreements/{$lease->id}/card", array_merge([
            'setup_intent_id' => 'seti_ok',
            'customer_agreed' => true,
        ], $body));
    }

    public function test_an_admin_starts_a_card_setup_for_the_customers_stripe_profile(): void
    {
        $lease = $this->lease();
        $this->stripe->queue(['id' => 'seti_new', 'object' => 'setup_intent', 'client_secret' => 'seti_new_secret_x', 'customer' => 'cus_card']);

        $this->actingAs($this->admin(), 'sanctum')->postJson("/api/admin/lease-agreements/{$lease->id}/card/setup-intent")
            ->assertOk()
            ->assertJsonPath('data.client_secret', 'seti_new_secret_x');

        $params = $this->stripe->requests[0]['params'];
        $this->assertSame('cus_card', $params['customer']);
        $this->assertSame(['card'], $params['payment_method_types']);
        $this->assertSame('off_session', $params['usage']);
    }

    public function test_an_admin_saves_the_card_and_leaves_a_note_without_the_number(): void
    {
        $lease = $this->lease();
        $admin = $this->admin();
        $this->queueSucceededCard();

        $this->save($admin, $lease)->assertOk()->assertJsonPath('data.card.last4', '4242');

        $this->assertSame('pm_new_card', $lease->fresh()->stripe_card_payment_method_id);
        $note = $lease->application->dealerNotes()->first();
        $this->assertSame($admin->id, $note->author_user_id);
        $this->assertStringContainsString('Visa ending 4242', $note->text);
        $this->assertStringContainsString($admin->name, $note->text);
    }

    public function test_it_needs_the_customer_to_have_agreed(): void
    {
        $lease = $this->lease();

        $this->save($this->admin(), $lease, ['customer_agreed' => false])->assertStatus(422);

        $this->assertNull($lease->fresh()->stripe_card_payment_method_id);
        $this->assertCount(0, $this->stripe->requests);
    }

    public function test_a_card_stripe_did_not_accept_is_not_saved(): void
    {
        $lease = $this->lease();
        $this->stripe->queue(['id' => 'seti_ok', 'object' => 'setup_intent', 'status' => 'requires_payment_method', 'customer' => 'cus_card']);

        $this->save($this->admin(), $lease)->assertStatus(422);

        $this->assertNull($lease->fresh()->stripe_card_payment_method_id);
        $this->assertSame(0, $lease->application->dealerNotes()->count());
    }

    public function test_a_setup_that_belongs_to_another_customer_is_refused(): void
    {
        $lease = $this->lease();
        $this->queueSucceededCard('cus_someone_else');

        $this->save($this->admin(), $lease)->assertStatus(422);

        $this->assertNull($lease->fresh()->stripe_card_payment_method_id);
    }

    public function test_a_card_is_not_added_when_autopay_is_off_or_one_is_already_saved(): void
    {
        $off = $this->lease(['autopay_enabled' => false]);
        $has = $this->lease(['stripe_card_payment_method_id' => 'pm_existing']);
        $admin = $this->admin();

        $this->actingAs($admin, 'sanctum')->postJson("/api/admin/lease-agreements/{$off->id}/card/setup-intent")->assertStatus(422);
        $this->actingAs($admin, 'sanctum')->postJson("/api/admin/lease-agreements/{$has->id}/card/setup-intent")->assertStatus(422);
        $this->save($admin, $has)->assertStatus(422);

        $this->assertSame('pm_existing', $has->fresh()->stripe_card_payment_method_id);
        $this->assertCount(0, $this->stripe->requests);
    }

    public function test_a_card_is_not_added_before_the_deposit_stage(): void
    {
        $lease = $this->lease([], Application::STATUS_IN_VERIFICATION);

        $this->actingAs($this->admin(), 'sanctum')->postJson("/api/admin/lease-agreements/{$lease->id}/card/setup-intent")->assertStatus(422);

        $this->assertCount(0, $this->stripe->requests);
    }

    public function test_only_staff_with_payment_tracking_can_add_a_card(): void
    {
        $lease = $this->lease();

        $this->actingAs($lease->customer, 'sanctum')->postJson("/api/admin/lease-agreements/{$lease->id}/card/setup-intent")->assertForbidden();

        $reviewer = User::factory()->create(['role' => User::ROLE_ADMIN]);
        AdminPermission::create(['user_id' => $reviewer->id, 'permission' => AdminPermission::APPLICATION_REVIEW]);
        $this->actingAs($reviewer, 'sanctum')->postJson("/api/admin/lease-agreements/{$lease->id}/card/setup-intent")->assertForbidden();
        $this->save($reviewer, $lease)->assertForbidden();

        $this->assertCount(0, $this->stripe->requests);
    }
}
