<?php

namespace Tests\Feature;

use App\Models\AdminPermission;
use App\Models\Application;
use App\Models\User;
use App\Notifications\ApplicationInfoProvidedNotification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

/**
 * Real gap found live 2026-09-16: needs_info deliberately has no forward
 * edge through the normal status update (an admin can't advance past a
 * request they themselves opened until the customer replies — see
 * FLOW/PRIMARY_LABEL on the frontend), but a customer often answers by
 * phone, text, or email instead of through the portal, leaving an admin
 * stuck with no way to move the application on. Covers the fix — an admin
 * endpoint that records the customer's answer on their behalf via the same
 * InfoRequestResponder path their own reply would take.
 */
class AdminRespondsOnBehalfTest extends TestCase
{
    use RefreshDatabase;

    private function applicationAwaitingInfo(): Application
    {
        $admin = User::factory()->create(['role' => User::ROLE_ADMIN]);
        $application = Application::factory()->create(['status' => Application::STATUS_WAITING_REVIEW]);

        $this->actingAs($admin, 'sanctum')->putJson("/api/admin/applications/{$application->id}", [
            'status' => Application::STATUS_NEEDS_INFO,
            'status_notes' => 'Do you have a bill for the electricity or utilities?',
        ])->assertOk();

        return $application->fresh();
    }

    public function test_admin_can_record_a_customers_phoned_in_answer(): void
    {
        Notification::fake();

        $application = $this->applicationAwaitingInfo();
        $admin = User::factory()->create(['role' => User::ROLE_ADMIN]);

        $response = $this->actingAs($admin, 'sanctum')->postJson(
            "/api/admin/applications/{$application->id}/info-requests/respond",
            ['reply_text' => 'Customer confirmed by phone, will email the bill.'],
        );

        $response->assertOk();
        $response->assertJsonPath('data.status', Application::STATUS_WAITING_REVIEW);

        $infoRequest = $application->infoRequests()->latest()->first();
        $this->assertSame('Customer confirmed by phone, will email the bill.', $infoRequest->reply_text);
        $this->assertNotNull($infoRequest->replied_at);

        Notification::assertSentTo(User::where('role', User::ROLE_ADMIN)->get(), ApplicationInfoProvidedNotification::class);
    }

    public function test_admin_can_attach_a_document_the_customer_sent_them_directly(): void
    {
        $application = $this->applicationAwaitingInfo();
        $admin = User::factory()->create(['role' => User::ROLE_ADMIN]);
        $file = UploadedFile::fake()->create('utility-bill.pdf', 200, 'application/pdf');

        $response = $this->actingAs($admin, 'sanctum')->postJson(
            "/api/admin/applications/{$application->id}/info-requests/respond",
            ['id_document' => $file],
        );

        $response->assertOk();
        $infoRequest = $application->infoRequests()->latest()->first();
        $this->assertNotNull($infoRequest->reply_document_path);
    }

    public function test_it_is_rejected_when_the_application_is_not_awaiting_information(): void
    {
        $admin = User::factory()->create(['role' => User::ROLE_ADMIN]);
        $application = Application::factory()->create(['status' => Application::STATUS_WAITING_REVIEW]);

        $response = $this->actingAs($admin, 'sanctum')->postJson(
            "/api/admin/applications/{$application->id}/info-requests/respond",
            ['reply_text' => 'Not applicable here.'],
        );

        $response->assertStatus(422);
    }

    public function test_a_review_only_admin_can_still_use_it(): void
    {
        $application = $this->applicationAwaitingInfo();
        $admin = User::factory()->create(['role' => User::ROLE_ADMIN]);
        AdminPermission::create(['user_id' => $admin->id, 'permission' => AdminPermission::APPLICATION_REVIEW]);

        $this->actingAs($admin, 'sanctum')->postJson(
            "/api/admin/applications/{$application->id}/info-requests/respond",
            ['reply_text' => 'Confirmed verbally.'],
        )->assertOk();
    }

    public function test_it_requires_the_application_review_permission(): void
    {
        $application = $this->applicationAwaitingInfo();
        $admin = User::factory()->create(['role' => User::ROLE_ADMIN]);
        AdminPermission::create(['user_id' => $admin->id, 'permission' => AdminPermission::PAYMENT_TRACKING]);

        $this->actingAs($admin, 'sanctum')->postJson(
            "/api/admin/applications/{$application->id}/info-requests/respond",
            ['reply_text' => 'Confirmed verbally.'],
        )->assertStatus(403);
    }
}
