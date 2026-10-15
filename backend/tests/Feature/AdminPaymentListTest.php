<?php

namespace Tests\Feature;

use App\Models\Application;
use App\Models\LeaseAgreement;
use App\Models\Payment;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Client, Joel, 2026-10-16: the admin Payments tab listed 2029 due dates and
 * customers who never picked up. The list now leaves out rows nobody can act on.
 */
class AdminPaymentListTest extends TestCase
{
    use RefreshDatabase;

    private function admin(): User
    {
        return User::factory()->create(['role' => User::ROLE_ADMIN]);
    }

    private function lease(string $applicationStatus): LeaseAgreement
    {
        return LeaseAgreement::factory()->create([
            'application_id' => Application::factory()->state(['status' => $applicationStatus]),
        ]);
    }

    private function row(LeaseAgreement $lease, string $type, string $status, string $due = '2026-11-15'): Payment
    {
        return Payment::factory()->create([
            'lease_agreement_id' => $lease->id,
            'type' => $type,
            'status' => $status,
            'due_date' => $due,
            'paid_date' => $status === Payment::STATUS_PAID ? $due : null,
        ]);
    }

    /** @return array<int> */
    private function listedIds(?int $leaseId = null): array
    {
        $url = '/api/admin/payments'.($leaseId ? "?lease_agreement_id={$leaseId}" : '');

        return collect($this->actingAs($this->admin(), 'sanctum')->getJson($url)->assertOk()->json('data'))->pluck('id')->all();
    }

    public function test_a_started_lease_lists_its_upcoming_and_overdue_monthly_payments(): void
    {
        $lease = $this->lease(Application::STATUS_FINISHED);
        $first = $this->row($lease, Payment::TYPE_RENTAL, Payment::STATUS_PAID, '2026-10-15');
        $next = $this->row($lease, Payment::TYPE_RENTAL, Payment::STATUS_PENDING, '2026-11-15');
        $failed = $this->row($lease, Payment::TYPE_RENTAL, Payment::STATUS_FAILED, '2026-12-15');

        $ids = $this->listedIds();

        $this->assertContains($first->id, $ids);
        $this->assertContains($next->id, $ids);
        $this->assertContains($failed->id, $ids);
    }

    public function test_the_leftover_schedule_of_a_lease_that_never_started_is_left_out(): void
    {
        $lease = $this->lease(Application::STATUS_WAITING_DEPOSIT);
        $leftover = $this->row($lease, Payment::TYPE_RENTAL, Payment::STATUS_PENDING, '2029-10-02');
        $deposit = $this->row($lease, Payment::TYPE_DEPOSIT, Payment::STATUS_PENDING, '2026-10-15');
        $declinedDeposit = $this->row($lease, Payment::TYPE_PICKUP_BALANCE, Payment::STATUS_FAILED, '2026-10-15');

        $ids = $this->listedIds();

        $this->assertNotContains($leftover->id, $ids);
        // A deposit or pickup balance that is stuck still needs a person.
        $this->assertContains($deposit->id, $ids);
        $this->assertContains($declinedDeposit->id, $ids);
    }

    public function test_open_payments_of_a_declined_or_withdrawn_application_are_left_out_but_money_that_moved_stays(): void
    {
        foreach ([Application::STATUS_DECLINED, Application::STATUS_WITHDRAWN] as $status) {
            $lease = $this->lease($status);
            $paid = $this->row($lease, Payment::TYPE_RENTAL, Payment::STATUS_PAID, '2026-10-15');
            $pending = $this->row($lease, Payment::TYPE_RENTAL, Payment::STATUS_PENDING, '2026-11-15');
            $failedDeposit = $this->row($lease, Payment::TYPE_DEPOSIT, Payment::STATUS_FAILED, '2026-10-15');
            $refunded = $this->row($lease, Payment::TYPE_DEPOSIT, Payment::STATUS_REFUNDED, '2026-10-15');

            $ids = $this->listedIds();

            $this->assertContains($paid->id, $ids, "paid kept for {$status}");
            $this->assertContains($refunded->id, $ids, "refunded kept for {$status}");
            $this->assertNotContains($pending->id, $ids, "pending hidden for {$status}");
            $this->assertNotContains($failedDeposit->id, $ids, "failed hidden for {$status}");
        }
    }

    public function test_one_leases_own_list_is_never_trimmed(): void
    {
        $lease = $this->lease(Application::STATUS_DECLINED);
        $leftover = $this->row($lease, Payment::TYPE_RENTAL, Payment::STATUS_PENDING, '2029-10-02');

        $this->assertContains($leftover->id, $this->listedIds($lease->id));
    }
}
