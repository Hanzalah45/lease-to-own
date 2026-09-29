<?php

namespace Tests\Feature;

use App\Models\AdminPermission;
use App\Models\Application;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Real gap found live 2026-09-30 (Joel, application #9, Juan Carlos
 * Martinez): the "Request Info" button has always been shown on both
 * waiting_review and waiting_approval, but LEGAL_STATUS_TRANSITIONS only
 * ever allowed needs_info from waiting_review — every click from the
 * approval-call stage was rejected with "This application cannot move from
 * 'waiting_approval' to 'needs_info'." Fixing reachability alone wasn't
 * enough: InfoRequestResponder::respond() unconditionally sent an answered
 * request back to waiting_review, which would have silently undone the
 * already-completed approval-call step once the request opened from
 * waiting_approval was answered. Covers both halves of the fix.
 */
class NeedsInfoFromApprovalTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_can_request_info_from_waiting_approval(): void
    {
        $application = Application::factory()->create(['status' => Application::STATUS_WAITING_APPROVAL]);
        $admin = User::factory()->create(['role' => User::ROLE_SUPER_ADMIN]);

        $response = $this->actingAs($admin, 'sanctum')->putJson("/api/admin/applications/{$application->id}", [
            'status' => Application::STATUS_NEEDS_INFO,
            'status_notes' => 'Can you send a current utility bill?',
        ]);

        $response->assertOk();
        $fresh = $application->fresh();
        $this->assertSame(Application::STATUS_NEEDS_INFO, $fresh->status);
        $this->assertSame(Application::STATUS_WAITING_APPROVAL, $fresh->pre_needs_info_status);

        $infoRequest = $application->infoRequests()->latest()->first();
        $this->assertSame('Can you send a current utility bill?', $infoRequest->request_text);
    }

    public function test_answering_a_request_opened_from_waiting_approval_returns_there_not_to_waiting_review(): void
    {
        $application = Application::factory()->create(['status' => Application::STATUS_WAITING_APPROVAL]);
        $admin = User::factory()->create(['role' => User::ROLE_SUPER_ADMIN]);

        $this->actingAs($admin, 'sanctum')->putJson("/api/admin/applications/{$application->id}", [
            'status' => Application::STATUS_NEEDS_INFO,
            'status_notes' => 'Can you send a current utility bill?',
        ])->assertOk();

        $this->actingAs($admin, 'sanctum')->postJson(
            "/api/admin/applications/{$application->id}/info-requests/respond",
            ['reply_text' => 'Customer sent it over by text, attaching here.'],
        )->assertOk();

        $fresh = $application->fresh();
        $this->assertSame(Application::STATUS_WAITING_APPROVAL, $fresh->status);
        $this->assertNull($fresh->pre_needs_info_status);
    }

    /** Unchanged behavior: a request opened from waiting_review still returns to waiting_review. */
    public function test_answering_a_request_opened_from_waiting_review_still_returns_there(): void
    {
        $application = Application::factory()->create(['status' => Application::STATUS_WAITING_REVIEW]);
        $admin = User::factory()->create(['role' => User::ROLE_SUPER_ADMIN]);

        $this->actingAs($admin, 'sanctum')->putJson("/api/admin/applications/{$application->id}", [
            'status' => Application::STATUS_NEEDS_INFO,
            'status_notes' => 'Missing your ID photo.',
        ])->assertOk();

        $this->actingAs($admin, 'sanctum')->postJson(
            "/api/admin/applications/{$application->id}/info-requests/respond",
            ['reply_text' => 'Here it is.'],
        )->assertOk();

        $this->assertSame(Application::STATUS_WAITING_REVIEW, $application->fresh()->status);
    }

    /**
     * Data that entered needs_info before pre_needs_info_status existed has
     * no recorded return stage — must not error, must fall back exactly to
     * the old hardcoded behavior.
     */
    public function test_a_request_with_no_recorded_pre_status_falls_back_to_waiting_review(): void
    {
        $application = Application::factory()->create([
            'status' => Application::STATUS_NEEDS_INFO,
            'pre_needs_info_status' => null,
        ]);
        $admin = User::factory()->create(['role' => User::ROLE_ADMIN]);
        $application->infoRequests()->create([
            'requested_by_user_id' => $admin->id,
            'request_text' => 'Pre-existing request from before this column existed.',
        ]);

        $this->actingAs($admin, 'sanctum')->postJson(
            "/api/admin/applications/{$application->id}/info-requests/respond",
            ['reply_text' => 'Answered.'],
        )->assertOk();

        $this->assertSame(Application::STATUS_WAITING_REVIEW, $application->fresh()->status);
    }

    public function test_needs_info_is_still_rejected_from_stages_that_never_offered_the_button(): void
    {
        $application = Application::factory()->create(['status' => Application::STATUS_IN_VERIFICATION]);
        $admin = User::factory()->create(['role' => User::ROLE_SUPER_ADMIN]);

        $response = $this->actingAs($admin, 'sanctum')->putJson("/api/admin/applications/{$application->id}", [
            'status' => Application::STATUS_NEEDS_INFO,
        ]);

        $response->assertStatus(422);
    }

    public function test_a_restricted_admin_without_application_review_still_cannot_request_info(): void
    {
        $application = Application::factory()->create(['status' => Application::STATUS_WAITING_APPROVAL]);
        $admin = User::factory()->create(['role' => User::ROLE_ADMIN]);
        AdminPermission::create(['user_id' => $admin->id, 'permission' => AdminPermission::PAYMENT_TRACKING]);

        $this->actingAs($admin, 'sanctum')->putJson("/api/admin/applications/{$application->id}", [
            'status' => Application::STATUS_NEEDS_INFO,
            'status_notes' => 'Need something.',
        ])->assertStatus(403);
    }
}
