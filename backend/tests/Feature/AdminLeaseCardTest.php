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

    public function test_a_card_can_be_saved_when_autopay_is_off(): void
    {
        // 2026-10-15: a customer with AutoPay off added the wrong card and neither she nor staff could change it. The
        // saved card is also what she pays with herself, so it does not depend on AutoPay.
        $off = $this->lease(['autopay_enabled' => false, 'stripe_card_payment_method_id' => 'pm_wrong_card']);
        $admin = $this->admin();
        $this->stripe->queue(['id' => 'pm_wrong_card', 'object' => 'payment_method', 'type' => 'card', 'card' => ['brand' => 'visa', 'last4' => '1111']]);
        $this->queueSucceededCard();
        $this->stripe->queue(['id' => 'pm_wrong_card', 'object' => 'payment_method']);

        $this->save($admin, $off)->assertOk()->assertJsonPath('data.replaced', true);

        $this->assertSame('pm_new_card', $off->fresh()->stripe_card_payment_method_id);
    }

    public function test_the_methods_can_be_read_when_autopay_is_off(): void
    {
        $off = $this->lease(['autopay_enabled' => false]);

        $this->actingAs($this->admin(), 'sanctum')->getJson("/api/admin/lease-agreements/{$off->id}/autopay-methods")
            ->assertOk()
            ->assertJsonPath('data.autopay_enabled', false);
    }

    public function test_changing_the_card_replaces_it_takes_the_old_one_off_stripe_and_leaves_a_note(): void
    {
        $lease = $this->lease(['stripe_card_payment_method_id' => 'pm_old_card']);
        $admin = $this->admin();
        // In the order the server asks: the old card (for the note), the new setup and card, then the old card's removal.
        $this->stripe->queue(['id' => 'pm_old_card', 'object' => 'payment_method', 'type' => 'card', 'card' => ['brand' => 'mastercard', 'last4' => '1111']]);
        $this->queueSucceededCard();
        $this->stripe->queue(['id' => 'pm_old_card', 'object' => 'payment_method']);

        $this->save($admin, $lease)->assertOk()->assertJsonPath('data.replaced', true)->assertJsonPath('data.card.last4', '4242');

        $this->assertSame('pm_new_card', $lease->fresh()->stripe_card_payment_method_id);
        $detach = collect($this->stripe->requests)->first(fn ($r) => str_contains($r['url'], '/payment_methods/pm_old_card/detach'));
        $this->assertNotNull($detach, 'the replaced card should be detached from the customer');
        $note = $lease->application->dealerNotes()->first()->text;
        $this->assertStringContainsString('Mastercard ending 1111 replaced by Visa ending 4242', $note);
        $this->assertStringContainsString($admin->name, $note);
    }

    public function test_a_replacement_card_stripe_did_not_accept_leaves_the_old_card_alone(): void
    {
        $lease = $this->lease(['stripe_card_payment_method_id' => 'pm_old_card']);
        $this->stripe->queue(['id' => 'pm_old_card', 'object' => 'payment_method', 'type' => 'card', 'card' => ['brand' => 'visa', 'last4' => '1111']]);
        $this->stripe->queue(['id' => 'seti_ok', 'object' => 'setup_intent', 'status' => 'requires_payment_method', 'customer' => 'cus_card']);

        $this->save($this->admin(), $lease)->assertStatus(422);

        $this->assertSame('pm_old_card', $lease->fresh()->stripe_card_payment_method_id);
        $this->assertFalse(collect($this->stripe->requests)->contains(fn ($r) => str_contains($r['url'], '/detach')));
    }

    public function test_staff_see_the_brand_last_four_and_expiry_of_the_methods_on_file(): void
    {
        $lease = $this->lease(['stripe_card_payment_method_id' => 'pm_card_saved', 'stripe_bank_payment_method_id' => 'pm_bank_saved', 'autopay_primary_method' => 'ach']);
        $this->stripe->queue(['id' => 'pm_bank_saved', 'object' => 'payment_method', 'type' => 'us_bank_account', 'us_bank_account' => ['bank_name' => 'STRIPE TEST BANK', 'last4' => '6789', 'account_type' => 'checking']]);
        $this->stripe->queue(['id' => 'pm_card_saved', 'object' => 'payment_method', 'type' => 'card', 'card' => ['brand' => 'visa', 'last4' => '4242', 'exp_month' => 12, 'exp_year' => 2030, 'funding' => 'credit']]);

        $response = $this->actingAs($this->admin(), 'sanctum')->getJson("/api/admin/lease-agreements/{$lease->id}/autopay-methods")->assertOk();

        $response->assertJsonPath('data.primary', 'ach')
            ->assertJsonPath('data.bank.bank_name', 'STRIPE TEST BANK')
            ->assertJsonPath('data.bank.last4', '6789')
            ->assertJsonPath('data.card.brand', 'visa')
            ->assertJsonPath('data.card.last4', '4242')
            ->assertJsonPath('data.card.exp_year', 2030);
        // Only the card brand, last four and expiry: no ids or full numbers.
        $this->assertStringNotContainsString('pm_card_saved', $response->getContent());
        $this->assertStringNotContainsString('pm_bank_saved', $response->getContent());
    }

    public function test_the_methods_page_still_loads_when_stripe_cannot_be_reached(): void
    {
        $lease = $this->lease(['stripe_card_payment_method_id' => 'pm_card_saved']);
        $this->stripe->queueException(new \Stripe\Exception\ApiConnectionException('timed out'));

        $this->actingAs($this->admin(), 'sanctum')->getJson("/api/admin/lease-agreements/{$lease->id}/autopay-methods")
            ->assertOk()
            ->assertJsonPath('data.card.unavailable', true)
            ->assertJsonPath('data.bank', null);
    }

    public function test_a_lease_with_no_methods_shows_none(): void
    {
        $lease = $this->lease();

        $this->actingAs($this->admin(), 'sanctum')->getJson("/api/admin/lease-agreements/{$lease->id}/autopay-methods")
            ->assertOk()
            ->assertJsonPath('data.card', null)
            ->assertJsonPath('data.bank', null);
        $this->assertCount(0, $this->stripe->requests);
    }

    public function test_a_card_is_not_added_before_the_deposit_stage(): void
    {
        $lease = $this->lease([], Application::STATUS_IN_VERIFICATION);

        $this->actingAs($this->admin(), 'sanctum')->postJson("/api/admin/lease-agreements/{$lease->id}/card/setup-intent")->assertStatus(422);

        $this->assertCount(0, $this->stripe->requests);
    }

    /** @return array<string, string> the signed parameters of a link, as the page sends them back. */
    private function linkParams(string $url): array
    {
        parse_str((string) parse_url($url, PHP_URL_QUERY), $query);

        return $query;
    }

    public function test_staff_get_a_short_lived_page_for_the_customer_to_link_their_own_bank_account(): void
    {
        $lease = $this->lease();
        $this->travelTo(now()->startOfSecond());

        $response = $this->actingAs($this->admin(), 'sanctum')->postJson("/api/admin/lease-agreements/{$lease->id}/bank-link")
            ->assertOk()
            ->assertJsonPath('data.expires_in_minutes', 30);

        $url = $response->json('data.url');
        $query = $this->linkParams($url);
        $this->assertStringContainsString('/setup-autopay?', $url);
        $this->assertSame('1', $query['office']);
        $this->assertEqualsWithDelta(now()->addMinutes(30)->timestamp, (int) $query['expires'], 2);

        // The same signed page the emailed link opens accepts it, for this customer and lease only.
        $this->postJson('/api/payment-methods/verify-show', $query)
            ->assertOk()
            ->assertJsonPath('data.customer_email', $lease->customer->email);
        $this->postJson('/api/payment-methods/verify-show', array_merge($query, ['lease' => $lease->id + 1]))->assertStatus(422);

        // Gone once the half hour is over.
        $this->travel(31)->minutes();
        $this->postJson('/api/payment-methods/verify-show', $query)->assertStatus(422);

        $this->assertCount(0, $this->stripe->requests);
    }

    public function test_opening_the_bank_page_leaves_a_note_on_the_application(): void
    {
        $lease = $this->lease();
        $admin = $this->admin();

        $this->actingAs($admin, 'sanctum')->postJson("/api/admin/lease-agreements/{$lease->id}/bank-link")->assertOk();

        $note = $lease->application->dealerNotes()->latest('id')->first();
        $this->assertStringContainsString($admin->name, $note->text);
        $this->assertStringContainsString('link a bank account', $note->text);
    }

    public function test_the_bank_page_is_not_opened_before_the_deposit_stage(): void
    {
        $lease = $this->lease([], Application::STATUS_IN_VERIFICATION);

        $this->actingAs($this->admin(), 'sanctum')->postJson("/api/admin/lease-agreements/{$lease->id}/bank-link")->assertStatus(422);
    }

    public function test_only_staff_with_payment_tracking_can_open_the_bank_page(): void
    {
        $lease = $this->lease();

        $this->actingAs($lease->customer, 'sanctum')->postJson("/api/admin/lease-agreements/{$lease->id}/bank-link")->assertForbidden();

        $reviewer = User::factory()->create(['role' => User::ROLE_ADMIN]);
        AdminPermission::create(['user_id' => $reviewer->id, 'permission' => AdminPermission::APPLICATION_REVIEW]);
        $this->actingAs($reviewer, 'sanctum')->postJson("/api/admin/lease-agreements/{$lease->id}/bank-link")->assertForbidden();
    }

    public function test_only_staff_with_payment_tracking_can_add_a_card(): void
    {
        $lease = $this->lease();

        $this->actingAs($lease->customer, 'sanctum')->postJson("/api/admin/lease-agreements/{$lease->id}/card/setup-intent")->assertForbidden();

        $reviewer = User::factory()->create(['role' => User::ROLE_ADMIN]);
        AdminPermission::create(['user_id' => $reviewer->id, 'permission' => AdminPermission::APPLICATION_REVIEW]);
        $this->actingAs($reviewer, 'sanctum')->postJson("/api/admin/lease-agreements/{$lease->id}/card/setup-intent")->assertForbidden();
        $this->actingAs($reviewer, 'sanctum')->getJson("/api/admin/lease-agreements/{$lease->id}/autopay-methods")->assertForbidden();
        $this->actingAs($lease->customer, 'sanctum')->getJson("/api/admin/lease-agreements/{$lease->id}/autopay-methods")->assertForbidden();
        $this->save($reviewer, $lease)->assertForbidden();

        $this->assertCount(0, $this->stripe->requests);
    }
}
