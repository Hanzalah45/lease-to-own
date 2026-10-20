<?php

namespace Tests\Feature;

use App\Models\AdminPermission;
use App\Models\Application;
use App\Models\LeaseAgreement;
use App\Models\Payment;
use App\Models\PaymentAuditLog;
use App\Models\User;
use App\Services\AuditLog;
use App\Services\LeaseEngine;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Stripe\WebhookSignature;
use Tests\TestCase;

/**
 * The payment audit trail and internal payment notes (client, Joel, 2026-10-19 checklist, sections 9 and 11): who did what to a payment,
 * when, with which method, from where; and what staff write about a payment. Customers never see either.
 */
class PaymentAuditAndNotesTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(Carbon::parse('2026-10-20 15:00:00', 'UTC'));
        $this->admin = User::factory()->create(['role' => User::ROLE_SUPER_ADMIN, 'name' => 'Stefany Staff']);
    }

    private function payment(array $extra = []): Payment
    {
        $customer = User::factory()->create(['role' => User::ROLE_CUSTOMER, 'name' => 'Jane Doe']);
        $application = Application::factory()->create(['customer_id' => $customer->id, 'status' => Application::STATUS_FINISHED]);
        $lease = LeaseAgreement::factory()->create([
            'application_id' => $application->id, 'customer_id' => $customer->id, 'equipment_unit_id' => null,
            'monthly_rental_payment' => 200, 'sales_tax_rate' => 0.0825, 'ldw_selected' => false, 'ldw_amount' => 0,
        ]);

        return Payment::factory()->create(array_merge([
            'lease_agreement_id' => $lease->id, 'type' => Payment::TYPE_RENTAL, 'amount' => 216.50,
            'due_date' => '2026-10-15', 'status' => Payment::STATUS_PENDING,
        ], $extra));
    }

    private function asAdmin(): static
    {
        return $this->withHeaders(['User-Agent' => 'TestBrowser/1.0 (Windows)'])->actingAs($this->admin, 'sanctum');
    }

    private function webhook(string $type, array $object): TestResponse
    {
        config(['services.stripe.webhook_secret' => 'whsec_test_secret_for_specs_only']);
        $payload = json_encode(['id' => 'evt_'.Str::random(16), 'object' => 'event', 'type' => $type, 'data' => ['object' => $object]]);

        return $this->call('POST', '/api/webhooks/stripe', [], [], [], [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_Stripe-Signature' => WebhookSignature::generateSignatureHeader($payload, 'whsec_test_secret_for_specs_only'),
        ], $payload);
    }

    // ---- the audit trail ----

    public function test_marking_a_payment_paid_records_who_when_what_method_and_from_where(): void
    {
        $payment = $this->payment();

        $this->asAdmin()->putJson("/api/admin/payments/{$payment->id}", ['status' => 'paid', 'method' => 'cash'])->assertOk();

        $row = PaymentAuditLog::where('payment_id', $payment->id)->firstOrFail();
        $this->assertSame('payment.paid', $row->action);
        $this->assertSame('staff', $row->actor_type);
        $this->assertSame($this->admin->id, $row->user_id);
        $this->assertSame('Stefany Staff', $row->actor_name);
        $this->assertSame('cash', $row->method);
        $this->assertSame('216.50', $row->amount);
        $this->assertSame($payment->lease_agreement_id, $row->lease_agreement_id);
        $this->assertSame('127.0.0.1', $row->ip_address);
        $this->assertSame('TestBrowser/1.0 (Windows)', $row->user_agent);
        $this->assertSame('2026-10-20 15:00:00', $row->created_at->toDateTimeString());
        $this->assertStringContainsString('went from pending to paid', $row->summary);
    }

    public function test_saving_the_same_status_again_writes_nothing_more(): void
    {
        $payment = $this->payment();
        $this->asAdmin()->putJson("/api/admin/payments/{$payment->id}", ['status' => 'paid', 'method' => 'cash'])->assertOk();
        $this->asAdmin()->putJson("/api/admin/payments/{$payment->id}", ['status' => 'paid', 'method' => 'cash'])->assertOk();

        $this->assertSame(1, PaymentAuditLog::where('payment_id', $payment->id)->count());
    }

    public function test_a_stripe_event_is_recorded_as_the_system_with_no_address(): void
    {
        $payment = $this->payment(['stripe_payment_intent_id' => 'pi_sys']);

        $this->webhook('payment_intent.succeeded', ['id' => 'pi_sys', 'object' => 'payment_intent', 'status' => 'succeeded'])->assertOk();

        $row = PaymentAuditLog::where('payment_id', $payment->id)->firstOrFail();
        $this->assertSame('payment.paid', $row->action);
        $this->assertSame('system', $row->actor_type);
        $this->assertNull($row->user_id);
        $this->assertNull($row->ip_address);
        $this->assertNull($row->user_agent);
    }

    public function test_the_trail_survives_the_person_being_deleted(): void
    {
        $payment = $this->payment();
        $this->asAdmin()->putJson("/api/admin/payments/{$payment->id}", ['status' => 'paid', 'method' => 'cash'])->assertOk();

        $this->admin->forceDelete();

        $row = PaymentAuditLog::where('payment_id', $payment->id)->firstOrFail();
        $this->assertNull($row->user_id);
        $this->assertSame('Stefany Staff', $row->actor_name);
    }

    public function test_writing_the_trail_never_breaks_the_action_it_records(): void
    {
        $payment = $this->payment();
        // A payment that does not exist any more: the insert fails, and the caller still gets to carry on.
        $result = AuditLog::record('payment.paid', 'x', ['payment' => new Payment(['lease_agreement_id' => 999999]), 'lease' => null]);

        $this->assertNull($result);
        $this->assertSame(Payment::STATUS_PENDING, $payment->fresh()->status);
    }

    public function test_a_customer_action_is_recorded_as_the_customer(): void
    {
        $payment = $this->payment();
        $customer = $payment->leaseAgreement->customer;

        $this->actingAs($customer, 'sanctum');
        LeaseEngine::applyPaymentStatusChange($payment, Payment::STATUS_PAID, 'card', null);

        $row = PaymentAuditLog::where('payment_id', $payment->id)->firstOrFail();
        $this->assertSame('customer', $row->actor_type);
        $this->assertSame($customer->id, $row->user_id);
    }

    public function test_the_audit_list_filters_by_payment_family_who_and_dates_and_pages(): void
    {
        $one = $this->payment();
        $two = $this->payment();
        $this->asAdmin()->putJson("/api/admin/payments/{$one->id}", ['status' => 'paid', 'method' => 'cash'])->assertOk();
        $this->travelTo(Carbon::parse('2026-10-22 15:00:00', 'UTC'));
        $this->asAdmin()->putJson("/api/admin/payments/{$two->id}", ['status' => 'failed'])->assertOk();
        AuditLog::record('refund.completed', 'A refund went back.', ['payment' => $one, 'system' => true]);

        $all = $this->asAdmin()->getJson('/api/admin/payment-audit-log')->assertOk();
        $this->assertSame(3, $all->json('total'));
        // Newest first.
        $this->assertSame('refund.completed', $all->json('data.0.action'));

        $this->assertSame(2, $this->asAdmin()->getJson("/api/admin/payment-audit-log?payment_id={$one->id}")->json('total'));
        $this->assertSame(1, $this->asAdmin()->getJson('/api/admin/payment-audit-log?action=refund')->json('total'));
        $this->assertSame(1, $this->asAdmin()->getJson('/api/admin/payment-audit-log?action=payment.failed')->json('total'));
        $this->assertSame(1, $this->asAdmin()->getJson('/api/admin/payment-audit-log?who=system')->json('total'));
        $this->assertSame(2, $this->asAdmin()->getJson('/api/admin/payment-audit-log?who=staff')->json('total'));
        $this->assertSame(1, $this->asAdmin()->getJson('/api/admin/payment-audit-log?from=2026-10-21&to=2026-10-23&who=staff')->json('total'));
        $this->assertSame(2, $this->asAdmin()->getJson("/api/admin/payment-audit-log?lease_agreement_id={$one->lease_agreement_id}")->json('total'));
        $this->asAdmin()->getJson('/api/admin/payment-audit-log?from=2026-10-23&to=2026-10-01')->assertStatus(422);
    }

    public function test_the_trail_cannot_be_changed_or_deleted_from_the_portal(): void
    {
        $payment = $this->payment();
        $this->asAdmin()->putJson("/api/admin/payments/{$payment->id}", ['status' => 'paid', 'method' => 'cash'])->assertOk();
        $row = PaymentAuditLog::firstOrFail();

        $this->asAdmin()->putJson("/api/admin/payment-audit-log/{$row->id}", ['summary' => 'x'])->assertStatus(404);
        $this->asAdmin()->deleteJson("/api/admin/payment-audit-log/{$row->id}")->assertStatus(404);
        $this->asAdmin()->postJson('/api/admin/payment-audit-log', ['summary' => 'x'])->assertStatus(405);
        $this->assertNull($row->fresh()->updated_at ?? null);
    }

    // ---- notes ----

    public function test_staff_add_notes_of_each_kind_and_see_them_with_the_history(): void
    {
        $payment = $this->payment(['status' => Payment::STATUS_FAILED]);

        $this->asAdmin()->postJson("/api/admin/payments/{$payment->id}/notes", ['kind' => 'failed', 'body' => '  Customer says the account was closed.  '])
            ->assertCreated()
            ->assertJsonPath('data.kind', 'failed')
            ->assertJsonPath('data.body', 'Customer says the account was closed.')
            ->assertJsonPath('data.author.name', 'Stefany Staff');
        $this->asAdmin()->postJson("/api/admin/payments/{$payment->id}/notes", ['kind' => 'chargeback', 'body' => 'Evidence sent in Stripe.'])->assertCreated();

        $history = $this->asAdmin()->getJson("/api/admin/payments/{$payment->id}/history")->assertOk();
        $this->assertSame(['chargeback', 'failed'], array_column($history->json('data.notes'), 'kind'));
        $this->assertSame(['note.added', 'note.added'], array_column($history->json('data.audit'), 'action'));

        $this->asAdmin()->getJson("/api/admin/payments/{$payment->id}")->assertJsonPath('data.notes.0.body', 'Customer says the account was closed.');
    }

    public function test_a_note_needs_a_listed_kind_and_some_text_of_a_sensible_length(): void
    {
        $payment = $this->payment();

        $this->asAdmin()->postJson("/api/admin/payments/{$payment->id}/notes", ['kind' => 'secret', 'body' => 'x'])->assertStatus(422)->assertJsonValidationErrors('kind');
        $this->asAdmin()->postJson("/api/admin/payments/{$payment->id}/notes", ['kind' => 'general', 'body' => ''])->assertStatus(422)->assertJsonValidationErrors('body');
        $this->asAdmin()->postJson("/api/admin/payments/{$payment->id}/notes", ['kind' => 'general', 'body' => str_repeat('a', 1001)])->assertStatus(422)->assertJsonValidationErrors('body');
        $this->assertSame(0, $payment->notes()->count());
    }

    public function test_customers_and_staff_without_payment_tracking_cannot_see_or_write_notes_or_the_trail(): void
    {
        $payment = $this->payment();
        $customer = $payment->leaseAgreement->customer;
        $reviewer = User::factory()->create(['role' => User::ROLE_ADMIN]);
        AdminPermission::create(['user_id' => $reviewer->id, 'permission' => AdminPermission::APPLICATION_REVIEW]);

        foreach ([$customer, $reviewer] as $user) {
            $this->actingAs($user, 'sanctum')->postJson("/api/admin/payments/{$payment->id}/notes", ['kind' => 'general', 'body' => 'x'])->assertForbidden();
            $this->actingAs($user, 'sanctum')->getJson("/api/admin/payments/{$payment->id}/history")->assertForbidden();
            $this->actingAs($user, 'sanctum')->getJson('/api/admin/payment-audit-log')->assertForbidden();
        }
    }

    public function test_the_customers_own_payment_list_never_carries_the_notes(): void
    {
        $payment = $this->payment();
        $payment->notes()->create(['kind' => 'general', 'body' => 'Internal only.', 'author_user_id' => $this->admin->id]);

        $response = $this->actingAs($payment->leaseAgreement->customer, 'sanctum')->getJson('/api/customer/payments')->assertOk();

        $this->assertStringNotContainsString('Internal only', $response->getContent());
        $this->assertStringNotContainsString('notes', $response->getContent());
    }
}
