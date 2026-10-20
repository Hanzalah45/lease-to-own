<?php

namespace App\Services;

use App\Models\Application;
use App\Models\EquipmentUnit;
use App\Models\LeaseAgreement;
use App\Models\Payment;
use App\Models\PaymentAttempt;
use App\Models\User;
use App\Notifications\EquipmentStatusChangedNotification;
use Illuminate\Support\Facades\DB;

/**
 * Staff end a lease that was already picked up (client, Joel, 2026-10-16: "is it possible for me to cancel those types of
 * situations or will I always need you to do these functions?"). Real gaps this closes: a customer who returned the
 * equipment kept being billed (taking the unit back only detached it from the lease), and an early purchase could not be
 * completed (the scheduled payments stayed, AutoPay kept charging).
 *
 * One step, in one transaction under a lock on the lease: AutoPay is switched off, the monthly payments nobody has been
 * charged for are removed, the equipment is released, and a note says who did it and why. Money already collected is NOT
 * refunded here, and a late fee already charged stays owed: both are for a person to settle.
 */
class LeaseEnder
{
    public const REASON_RETURNED = 'returned';

    public const REASON_PURCHASED = 'purchased';

    public const REASON_OTHER = 'other';

    public const REASONS = [self::REASON_RETURNED, self::REASON_PURCHASED, self::REASON_OTHER];

    private const LABELS = [
        self::REASON_RETURNED => 'the equipment was returned',
        self::REASON_PURCHASED => 'the customer completed the early purchase',
        self::REASON_OTHER => 'another reason',
    ];

    /**
     * @return array{removed: int, late_fees_left: int}
     */
    public function end(LeaseAgreement $lease, string $reason, ?string $note, User $admin): array
    {
        return DB::transaction(function () use ($lease, $reason, $note, $admin) {
            LeaseAgreement::whereKey($lease->id)->lockForUpdate()->firstOrFail();
            $locked = LeaseAgreement::with(['application', 'customer.customerProfile', 'equipmentUnit'])->findOrFail($lease->id);

            abort_if($locked->hasEnded(), 422, 'This lease has already ended.');
            abort_unless(
                $locked->application?->status === Application::STATUS_FINISHED,
                422,
                'Only a lease that has been picked up can be ended. Decline or withdraw an application before pickup instead.',
            );
            abort_if($locked->ownership_status === LeaseAgreement::OWNERSHIP_OWNED, 422, 'This equipment is already paid off.');

            // A charge still on its way (a bank debit takes days) would land on a lease nobody is billing any more.
            $processing = $locked->payments()
                ->where(fn ($q) => $q->where(fn ($p) => $p->where('status', Payment::STATUS_PENDING)->whereNotNull('stripe_payment_intent_id'))
                    ->orWhereHas('attempts', fn ($a) => $a->whereIn('status', [PaymentAttempt::STATUS_INITIATED, PaymentAttempt::STATUS_PROCESSING])))
                ->exists();
            abort_if($processing, 422, 'A payment is still processing. Wait until it clears before ending the lease.');

            // The monthly payments nobody has been charged for. A late fee already charged is kept: it is still owed.
            $removed = $locked->payments()
                ->where('type', Payment::TYPE_RENTAL)
                ->where('status', Payment::STATUS_PENDING)
                ->whereNull('stripe_payment_intent_id')
                ->delete();
            $lateFeesLeft = $locked->payments()
                ->where('type', Payment::TYPE_LATE_FEE)
                ->whereIn('status', [Payment::STATUS_PENDING, Payment::STATUS_FAILED])
                ->count();

            $locked->update([
                'autopay_enabled' => false,
                'ended_at' => now(),
                'ended_reason' => $reason,
                'ended_by' => $admin->id,
                'ownership_status' => $reason === self::REASON_PURCHASED ? LeaseAgreement::OWNERSHIP_OWNED : $locked->ownership_status,
            ]);

            $this->releaseEquipment($locked, $reason, $admin);

            $locked->application->dealerNotes()->create([
                'author_user_id' => $admin->id,
                'text' => sprintf(
                    'Lease ended because %s. %d scheduled monthly payment%s removed and AutoPay turned off.%s Ended by %s.%s',
                    self::LABELS[$reason],
                    $removed,
                    $removed === 1 ? '' : 's',
                    $lateFeesLeft > 0 ? " {$lateFeesLeft} unpaid late fee".($lateFeesLeft === 1 ? '' : 's').' remain owed.' : '',
                    $admin->name,
                    $note ? " Note: {$note}" : '',
                ),
            ]);

            AuditLog::record('lease.ended', sprintf('Lease ended because %s: %d scheduled payment%s removed, AutoPay turned off.', self::LABELS[$reason], $removed, $removed === 1 ? '' : 's'), [
                'lease' => $locked, 'actor' => $admin, 'details' => ['reason' => $reason, 'note' => $note],
            ]);

            return ['removed' => $removed, 'late_fees_left' => $lateFeesLeft];
        });
    }

    /** Returned: the unit comes back and no longer belongs to the lease. Purchased: the customer owns it. Other: left as it is. */
    private function releaseEquipment(LeaseAgreement $lease, string $reason, User $admin): void
    {
        $unit = $lease->equipmentUnit;
        if (! $unit || $reason === self::REASON_OTHER) {
            return;
        }

        if ($reason === self::REASON_RETURNED) {
            LeaseAgreement::where('equipment_unit_id', $unit->id)->update(['equipment_unit_id' => null]);
            $unit->update(['status' => EquipmentUnit::STATUS_RETURNED, 'updated_by' => $admin->id]);
            $message = "Your {$unit->model} has been marked returned.";
        } else {
            $unit->update(['status' => EquipmentUnit::STATUS_OWNED_BY_CUSTOMER, 'updated_by' => $admin->id]);
            $message = "Congratulations! You now own your {$unit->model}!";
        }

        if ($lease->customer?->customerProfile?->status_change_emails ?? true) {
            $lease->customer?->notify(new EquipmentStatusChangedNotification($message));
        }
    }
}
