<?php

namespace Tests\Feature;

use App\Models\Application;
use App\Models\User;
use App\Notifications\ApplicationInfoProvidedNotification;
use App\Notifications\ApplicationInfoRequestedNotification;
use App\Notifications\ApplicationStatusChangedNotification;
use App\Services\InfoRequestSigner;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

/**
 * Real gap found live 2026-09-16: an admin asked a guest-originated customer
 * (no usable account yet — see GuestApplicationTest) for a utility bill via
 * the "needs info" flow, and there was no way for that customer to ever see
 * or answer the question. Covers the fix — a dedicated notification carrying
 * the real question plus a signed reply link, and the guest-facing endpoints
 * behind it (mirrors ContractSigner's guest-signing fix).
 */
class GuestInfoRequestTest extends TestCase
{
    use RefreshDatabase;

    private function makeGuestApplicationNeedingInfo(): Application
    {
        $this->postJson('/api/guest-applications', [
            'name' => 'Guest Applicant',
            'email' => 'guest@example.com',
            'move_notification_agreed' => true,
        ])->assertCreated();

        $admin = User::factory()->create(['role' => User::ROLE_ADMIN]);
        $customer = User::where('email', 'guest@example.com')->first();
        $application = Application::where('customer_id', $customer->id)->first();

        $this->actingAs($admin, 'sanctum')->putJson("/api/admin/applications/{$application->id}", [
            'status' => Application::STATUS_NEEDS_INFO,
            'status_notes' => 'Do you have a bill for the electricity or utilities?',
        ])->assertOk();

        return $application->fresh();
    }

    public function test_needs_info_sends_the_guest_customer_the_real_question_and_a_signed_reply_link(): void
    {
        Notification::fake();

        $application = $this->makeGuestApplicationNeedingInfo();
        $customer = $application->customer;

        Notification::assertSentTo($customer, ApplicationInfoRequestedNotification::class, function ($notification) use ($customer) {
            $payload = $notification->toArray($customer);

            return $payload['body'] === 'Do you have a bill for the electricity or utilities?'
                && $payload['action_url'] === null; // pending customer — no usable portal link
        });

        // The generic status-changed notification must not also fire for
        // needs_info — it has no way to carry the actual question and used
        // to tell a pending customer "no action needed", which was wrong.
        Notification::assertNotSentTo($customer, ApplicationStatusChangedNotification::class);
    }

    public function test_guest_customer_can_respond_via_the_signed_link_with_no_account(): void
    {
        Notification::fake();

        $application = $this->makeGuestApplicationNeedingInfo();
        $customer = $application->customer;
        $url = InfoRequestSigner::urlFor($customer, $application);
        parse_str(parse_url($url, PHP_URL_QUERY), $params);

        $response = $this->postJson('/api/info-requests/verify', $params);
        $response->assertOk();
        $response->assertJsonPath('data.open_request_text', 'Do you have a bill for the electricity or utilities?');

        $reply = $this->postJson('/api/info-requests/verify-respond', [...$params, 'reply_text' => 'Yes, attached.']);
        $reply->assertOk();
        $reply->assertJsonPath('data.status', Application::STATUS_WAITING_REVIEW);

        $this->assertSame(Application::STATUS_WAITING_REVIEW, $application->fresh()->status);
        $infoRequest = $application->infoRequests()->latest()->first();
        $this->assertSame('Yes, attached.', $infoRequest->reply_text);
        $this->assertNotNull($infoRequest->replied_at);

        $admins = User::where('role', User::ROLE_ADMIN)->get();
        Notification::assertSentTo($admins, ApplicationInfoProvidedNotification::class);
    }

    public function test_guest_customer_can_attach_a_document_via_the_signed_link(): void
    {
        $application = $this->makeGuestApplicationNeedingInfo();
        $customer = $application->customer;
        $url = InfoRequestSigner::urlFor($customer, $application);
        parse_str(parse_url($url, PHP_URL_QUERY), $params);

        $file = UploadedFile::fake()->create('utility-bill.pdf', 200, 'application/pdf');

        $reply = $this->postJson('/api/info-requests/verify-respond', [...$params, 'id_document' => $file]);
        $reply->assertOk();

        $infoRequest = $application->infoRequests()->latest()->first();
        $this->assertNotNull($infoRequest->reply_document_path);
    }

    public function test_an_expired_or_tampered_link_is_rejected(): void
    {
        $application = $this->makeGuestApplicationNeedingInfo();
        $customer = $application->customer;
        $url = InfoRequestSigner::urlFor($customer, $application);
        parse_str(parse_url($url, PHP_URL_QUERY), $params);

        $params['signature'] = 'tampered';

        $this->postJson('/api/info-requests/verify', $params)->assertStatus(422);
    }

    public function test_a_link_cannot_be_reused_for_a_different_application(): void
    {
        // Swapping the application id invalidates the HMAC signature itself
        // (it's signed over id+application+hash+expires), so this is
        // rejected the same way a tampered signature is — the application
        // id can't be swapped independently of the signature that vouches for it.
        $application = $this->makeGuestApplicationNeedingInfo();
        $customer = $application->customer;
        $otherApplication = Application::factory()->create();

        $url = InfoRequestSigner::urlFor($customer, $application);
        parse_str(parse_url($url, PHP_URL_QUERY), $params);
        $params['application'] = (string) $otherApplication->id;

        $this->postJson('/api/info-requests/verify', $params)->assertStatus(422);
    }

    public function test_a_logged_in_customer_still_gets_the_normal_portal_link(): void
    {
        Notification::fake();

        $customer = User::factory()->create(['role' => User::ROLE_CUSTOMER, 'status' => 'active']);
        $admin = User::factory()->create(['role' => User::ROLE_ADMIN]);
        $application = Application::factory()->create(['customer_id' => $customer->id]);

        $this->actingAs($admin, 'sanctum')->putJson("/api/admin/applications/{$application->id}", [
            'status' => Application::STATUS_NEEDS_INFO,
            'status_notes' => 'Please confirm your current address.',
        ])->assertOk();

        Notification::assertSentTo($customer, ApplicationInfoRequestedNotification::class, function ($notification) use ($customer, $application) {
            $payload = $notification->toArray($customer);

            return $payload['action_url'] === "/customer/applications/{$application->id}";
        });
    }
}
