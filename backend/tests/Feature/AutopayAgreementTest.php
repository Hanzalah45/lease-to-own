<?php

namespace Tests\Feature;

use App\Models\Application;
use App\Models\AutopayAgreement;
use App\Models\LeaseAgreement;
use App\Models\Payment;
use App\Models\User;
use App\Notifications\AutopayAgreedNotification;
use App\Services\AutopayAgreementService;
use App\Services\AutopayCharger;
use App\Services\PaymentMethodSigner;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Notifications\Events\NotificationSending;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

/**
 * The AutoPay tick box (client, Joel, 2026-10-16 asked, 2026-10-20 wrote the words): a customer who signed without AutoPay turns it on
 * from the portal with a tick box instead of signing the contract again. The agreement is kept (who, when, the exact words and the
 * terms shown), and AutoPay starts with the first payment due on or after that day.
 */
class AutopayAgreementTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        // 10:00 in Texas on the 15th.
        $this->travelTo(Carbon::parse('2026-10-15 15:00:00', 'UTC'));
    }

    /** A picked-up lease with AutoPay off and a card on file. */
    private function lease(array $overrides = []): LeaseAgreement
    {
        $customer = User::factory()->create(['role' => User::ROLE_CUSTOMER, 'name' => 'Tick Box Customer']);

        return LeaseAgreement::factory()->pickedUp()->create(array_merge([
            'customer_id' => $customer->id,
            'monthly_rental_payment' => 200,
            'sales_tax_rate' => 0,
            'autopay_enabled' => false,
            'stripe_card_payment_method_id' => 'pm_card',
        ], $overrides));
    }

    private function agree(LeaseAgreement $lease, array $body = [], ?User $as = null)
    {
        return $this->actingAs($as ?? $lease->customer, 'sanctum')->postJson(
            "/api/customer/lease-agreements/{$lease->id}/autopay-agreement",
            $body + ['accepted' => true, 'wording_version' => AutopayAgreementService::WORDING_VERSION],
        );
    }

    private function methodsStatus(LeaseAgreement $lease): array
    {
        return $this->actingAs($lease->customer, 'sanctum')
            ->getJson("/api/customer/lease-agreements/{$lease->id}/payment-methods")
            ->assertOk()
            ->json('data');
    }

    public function test_the_box_is_offered_with_the_words_and_the_terms_when_autopay_is_off_and_a_method_is_saved(): void
    {
        $lease = $this->lease();
        Payment::factory()->create(['lease_agreement_id' => $lease->id, 'type' => Payment::TYPE_RENTAL, 'amount' => 200, 'due_date' => '2026-11-15', 'status' => Payment::STATUS_PENDING]);

        $offer = $this->methodsStatus($lease)['autopay_offer'];

        $this->assertSame(1, $offer['version']);
        $this->assertSame(AutopayAgreementService::WORDING, $offer['wording']);
        $this->assertStringStartsWith('I agree to pay my monthly lease payments automatically using the bank account or card saved on my lease', $offer['wording']);
        $this->assertStringEndsWith('does not require an additional signature.', $offer['wording']);
        $this->assertSame('Autopay Lease Payment Authorization', $offer['title']);
        $this->assertEquals(200.0, $offer['amounts']['bank']);
        $this->assertEquals(206.0, $offer['amounts']['card']);
        $this->assertSame('2026-11-15', $offer['first_payment']['due_date']);
        $this->assertNotEmpty($offer['terms']);
    }

    public function test_the_box_is_not_offered_without_a_saved_method_when_autopay_is_on_or_before_pickup(): void
    {
        $this->assertNull($this->methodsStatus($this->lease(['stripe_card_payment_method_id' => null]))['autopay_offer']);
        $this->assertNull($this->methodsStatus($this->lease(['autopay_enabled' => true]))['autopay_offer']);

        $beforePickup = $this->lease();
        $beforePickup->application->update(['status' => Application::STATUS_WAITING_DEPOSIT]);
        $this->assertNull($this->methodsStatus($beforePickup)['autopay_offer']);
    }

    public function test_ticking_the_box_turns_autopay_on_and_keeps_the_agreement(): void
    {
        Notification::fake();
        $lease = $this->lease();
        $staff = User::factory()->create(['role' => User::ROLE_SUPER_ADMIN]);

        $response = $this->agree($lease)->assertOk();

        $response->assertJsonPath('data.autopay_enabled', true);
        $response->assertJsonPath('data.autopay_offer', null);

        $lease->refresh();
        $this->assertTrue($lease->autopay_enabled);
        $this->assertNull($lease->autopay_paused_at);
        $this->assertSame('2026-10-15', $lease->autopay_started_on->toDateString());

        $agreement = AutopayAgreement::where('lease_agreement_id', $lease->id)->firstOrFail();
        $this->assertSame($lease->customer_id, $agreement->user_id);
        $this->assertSame(1, $agreement->wording_version);
        $this->assertSame(AutopayAgreementService::WORDING, $agreement->wording);
        $this->assertSame('portal', $agreement->via);
        $this->assertNotNull($agreement->ip_address);
        $this->assertSame('2026-10-15 15:00:00', $agreement->agreed_at->toDateTimeString());
        // The terms that were shown are kept with the words.
        $this->assertSame(200.0, (float) $agreement->authorization['amounts']['bank']);
        $this->assertNotEmpty($agreement->authorization['terms']);

        $this->assertDatabaseHas('dealer_notes', ['application_id' => $lease->application_id, 'author_user_id' => $lease->customer_id]);
        Notification::assertSentTo($lease->customer, AutopayAgreedNotification::class);
        Notification::assertSentTo($staff, AutopayAgreedNotification::class);
    }

    public function test_the_customer_gets_a_copy_of_what_they_agreed_to_by_email(): void
    {
        $lease = $this->lease();
        $this->agree($lease)->assertOk();
        $agreement = AutopayAgreement::firstOrFail();

        $mail = (new AutopayAgreedNotification($lease->fresh(), $agreement))->toMail($lease->customer)->render();

        $this->assertStringContainsString('I agree to pay my monthly lease payments automatically', (string) $mail);
        $this->assertStringContainsString('October 15, 2026 at 10:00 AM (Central time)', (string) $mail);
    }

    public function test_the_box_must_be_ticked_and_the_words_must_be_the_ones_that_were_shown(): void
    {
        $lease = $this->lease();

        $this->agree($lease, ['accepted' => false])->assertStatus(422);
        $this->actingAs($lease->customer, 'sanctum')
            ->postJson("/api/customer/lease-agreements/{$lease->id}/autopay-agreement", ['wording_version' => 1])
            ->assertStatus(422);
        $this->agree($lease, ['wording_version' => 99])->assertStatus(422)->assertJsonPath('message', 'The wording was updated. Please read it again and tick the box.');

        $this->assertFalse($lease->fresh()->autopay_enabled);
        $this->assertSame(0, AutopayAgreement::count());
    }

    public function test_it_is_refused_when_autopay_is_already_on_nothing_is_saved_the_lease_ended_or_it_is_not_the_customers(): void
    {
        $this->agree($this->lease(['autopay_enabled' => true]))->assertStatus(422);
        $this->agree($this->lease(['stripe_card_payment_method_id' => null]))->assertStatus(422);
        $this->agree($this->lease(['ended_at' => now(), 'ended_reason' => 'other']))->assertStatus(422);

        $lease = $this->lease();
        $stranger = User::factory()->create(['role' => User::ROLE_CUSTOMER]);
        $this->agree($lease, [], $stranger)->assertNotFound();

        $this->assertSame(0, AutopayAgreement::count());
    }

    public function test_a_payment_already_due_is_not_charged_by_surprise_but_the_next_one_is(): void
    {
        $lease = $this->lease();
        $overdue = Payment::factory()->create(['lease_agreement_id' => $lease->id, 'type' => Payment::TYPE_RENTAL, 'amount' => 200, 'due_date' => '2026-10-01', 'status' => Payment::STATUS_PENDING]);
        $dueToday = Payment::factory()->create(['lease_agreement_id' => $lease->id, 'type' => Payment::TYPE_RENTAL, 'amount' => 200, 'due_date' => '2026-10-15', 'status' => Payment::STATUS_PENDING]);

        $this->agree($lease)->assertOk();

        $charger = app(AutopayCharger::class);
        $skipped = $charger->plan($overdue->fresh());
        $this->assertSame('skip', $skipped['action']);
        $this->assertSame('AutoPay was turned on after this payment was due.', $skipped['reason']);
        $this->assertNotSame('skip', $charger->plan($dueToday->fresh())['action']);
    }

    public function test_a_lease_whose_autopay_was_never_turned_on_with_the_box_has_no_start_limit(): void
    {
        $lease = $this->lease(['autopay_enabled' => true, 'autopay_started_on' => null]);
        $overdue = Payment::factory()->create(['lease_agreement_id' => $lease->id, 'type' => Payment::TYPE_RENTAL, 'amount' => 200, 'due_date' => '2026-10-01', 'status' => Payment::STATUS_PENDING]);

        $this->assertNotSame('AutoPay was turned on after this payment was due.', app(AutopayCharger::class)->plan($overdue->fresh())['reason'] ?? null);
    }

    public function test_a_mail_problem_does_not_undo_or_fail_the_agreement(): void
    {
        Event::listen(NotificationSending::class, fn () => throw new \RuntimeException('mail is down'));
        $lease = $this->lease();

        $this->agree($lease)->assertOk();

        $this->assertTrue($lease->fresh()->autopay_enabled);
        $this->assertSame(1, AutopayAgreement::count());
    }

    public function test_a_guest_customer_ticks_the_box_from_the_emailed_link(): void
    {
        Notification::fake();
        $lease = $this->lease();
        $lease->customer->update(['status' => 'pending']);
        parse_str(parse_url(PaymentMethodSigner::urlFor($lease->customer, $lease), PHP_URL_QUERY), $params);

        $show = $this->postJson('/api/payment-methods/verify-show', $params)->assertOk();
        $this->assertSame(AutopayAgreementService::WORDING, $show->json('data.autopay_offer.wording'));

        $this->postJson('/api/payment-methods/verify-autopay', $params + ['accepted' => true, 'wording_version' => 1])
            ->assertOk()
            ->assertJsonPath('data.autopay_enabled', true);

        $this->assertTrue($lease->fresh()->autopay_enabled);
        $this->assertSame('signed_link', AutopayAgreement::firstOrFail()->via);
    }

    public function test_the_signed_link_cannot_be_tampered_with_or_pointed_at_another_lease(): void
    {
        $lease = $this->lease();
        $lease->customer->update(['status' => 'pending']);
        parse_str(parse_url(PaymentMethodSigner::urlFor($lease->customer, $lease), PHP_URL_QUERY), $params);

        $this->postJson('/api/payment-methods/verify-autopay', ['signature' => 'tampered'] + $params + ['accepted' => true, 'wording_version' => 1])->assertStatus(422);

        // The lease number is part of what the link signs, so pointing it at another lease breaks the signature.
        $other = $this->lease();
        $this->postJson('/api/payment-methods/verify-autopay', ['lease' => (string) $other->id] + $params + ['accepted' => true, 'wording_version' => 1])->assertStatus(422);

        $this->assertSame(0, AutopayAgreement::count());
    }

    public function test_staff_see_who_agreed_and_when_but_not_the_ip_address_or_the_kept_text(): void
    {
        $lease = $this->lease();
        $this->agree($lease)->assertOk();
        $admin = User::factory()->create(['role' => User::ROLE_SUPER_ADMIN]);

        $response = $this->actingAs($admin, 'sanctum')->getJson("/api/admin/applications/{$lease->application_id}")->assertOk();

        $agreement = $response->json('data.lease_agreement.autopay_agreement') ?? $response->json('lease_agreement.autopay_agreement');
        $this->assertNotNull($agreement);
        $this->assertSame('portal', $agreement['via']);
        $this->assertArrayHasKey('agreed_at', $agreement);
        $this->assertArrayNotHasKey('ip_address', $agreement);
        $this->assertArrayNotHasKey('wording', $agreement);
        $this->assertArrayNotHasKey('authorization', $agreement);
    }
}
