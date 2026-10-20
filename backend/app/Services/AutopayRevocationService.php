<?php

namespace App\Services;

use App\Models\AutopayRevocation;
use App\Models\LeaseAgreement;
use App\Models\User;
use App\Notifications\AutopayRevocationNotification;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;

/**
 * A customer turns AutoPay off from the portal (client, Joel, 2026-10-19 checklist, section 5). The AutoPay authorization in the contract
 * says the customer may revoke by written notice at least three business days before the next scheduled payment, so the request is
 * kept with its day and AutoPay keeps charging until three business days later; on that day the system switches AutoPay off by itself.
 * Until then the customer can change their mind. Staff can still pause or turn AutoPay off at once, as before.
 */
class AutopayRevocationService
{
    /** The notice the authorization asks for, in business days. */
    public const NOTICE_BUSINESS_DAYS = 3;

    /** The request that has not taken effect yet, if there is one. */
    public static function pending(LeaseAgreement $lease): ?AutopayRevocation
    {
        return AutopayRevocation::where('lease_agreement_id', $lease->id)->where('status', AutopayRevocation::STATUS_PENDING)->latest('id')->first();
    }

    /** Whether a request has reached its day and AutoPay must not charge any more (the daily switch-off has not run yet). */
    public static function hasTakenEffect(LeaseAgreement $lease): bool
    {
        return AutopayRevocation::where('lease_agreement_id', $lease->id)
            ->where('status', AutopayRevocation::STATUS_PENDING)
            ->whereDate('effective_on', '<=', BillingClock::todayDate())
            ->exists();
    }

    /** Why a customer cannot ask to turn AutoPay off right now, or null when they can. */
    public static function blocker(LeaseAgreement $lease): ?string
    {
        if (! $lease->autopay_enabled) {
            return 'AutoPay is already off for this lease.';
        }
        if ($lease->hasEnded()) {
            return 'This lease has ended.';
        }
        if (self::pending($lease)) {
            return 'You already asked to turn AutoPay off.';
        }

        return null;
    }

    public function request(LeaseAgreement $lease, User $actor, ?Request $request, string $via = 'portal'): AutopayRevocation
    {
        $revocation = DB::transaction(function () use ($lease, $actor, $request, $via) {
            $locked = LeaseAgreement::with('application')->whereKey($lease->id)->lockForUpdate()->firstOrFail();
            abort_if($reason = self::blocker($locked), 422, $reason);

            $revocation = AutopayRevocation::create([
                'lease_agreement_id' => $locked->id,
                'user_id' => $actor->id,
                'requested_at' => now(),
                'effective_on' => BusinessDays::add(BillingClock::today(), self::NOTICE_BUSINESS_DAYS)->toDateString(),
                'via' => $via,
                'ip_address' => $request?->ip(),
                'user_agent' => $request ? Str::limit((string) $request->userAgent(), 300, '') : null,
                'status' => AutopayRevocation::STATUS_PENDING,
            ]);

            $locked->application?->dealerNotes()->create([
                'author_user_id' => $actor->id,
                'text' => sprintf(
                    '%s asked to turn AutoPay off on %s (Central time)%s. AutoPay keeps charging until %s, three business days later, and is then switched off by the system.',
                    $actor->isStaff() ? "{$actor->name} recorded that the customer" : 'The customer',
                    BillingClock::now()->format('M j, Y g:i A'),
                    $via === 'staff' ? ' in writing' : '',
                    $revocation->effective_on->format('M j, Y'),
                ),
            ]);

            return $revocation;
        });

        AuditLog::record('autopay.revocation_requested', sprintf('AutoPay turn-off requested; it takes effect on %s.', $revocation->effective_on->format('M j, Y')), [
            'lease' => $lease, 'actor' => $actor, 'details' => ['revocation_id' => $revocation->id, 'via' => $via, 'effective_on' => $revocation->effective_on->toDateString()],
        ]);
        $this->tell($lease, $revocation, AutopayRevocationNotification::REQUESTED);

        return $revocation;
    }

    /** The customer (or staff for them) keeps AutoPay on after all, before the day it would have stopped. */
    public function withdraw(LeaseAgreement $lease, User $actor): AutopayRevocation
    {
        $revocation = DB::transaction(function () use ($lease) {
            LeaseAgreement::whereKey($lease->id)->lockForUpdate()->firstOrFail();
            $pending = self::pending($lease);
            abort_unless($pending, 422, 'There is no request to turn AutoPay off.');
            abort_if(self::hasTakenEffect($lease), 422, 'AutoPay is being switched off today and cannot be kept.');

            $pending->update(['status' => AutopayRevocation::STATUS_WITHDRAWN, 'resolved_at' => now()]);

            return $pending;
        });

        AuditLog::record('autopay.revocation_withdrawn', 'The request to turn AutoPay off was withdrawn: AutoPay stays on.', [
            'lease' => $lease, 'actor' => $actor, 'details' => ['revocation_id' => $revocation->id],
        ]);
        $this->tell($lease, $revocation, AutopayRevocationNotification::WITHDRAWN);

        return $revocation;
    }

    /**
     * Switches AutoPay off for every request whose day has come. Run every morning, before the first charges of the day.
     *
     * @return int how many leases were switched off
     */
    public function applyDue(): int
    {
        $applied = 0;
        $due = AutopayRevocation::where('status', AutopayRevocation::STATUS_PENDING)->whereDate('effective_on', '<=', BillingClock::todayDate())->orderBy('id')->get();

        foreach ($due as $revocation) {
            $lease = LeaseAgreement::with('application')->find($revocation->lease_agreement_id);
            if (! $lease) {
                continue;
            }

            DB::transaction(function () use ($lease, $revocation) {
                LeaseAgreement::whereKey($lease->id)->lockForUpdate()->firstOrFail();
                $lease->update(['autopay_enabled' => false]);
                $revocation->update(['status' => AutopayRevocation::STATUS_APPLIED, 'resolved_at' => now()]);

                $lease->application?->dealerNotes()->create([
                    'author_user_id' => $revocation->user_id,
                    'text' => 'AutoPay was switched off, as the customer asked on '.$revocation->requested_at->setTimezone(BillingClock::TIMEZONE)->format('M j, Y').'. The customer now pays each monthly payment from the Payments page.',
                ]);
            });

            AuditLog::record('autopay.turned_off', 'AutoPay was switched off at the customer\'s request, three business days after it was asked for.', [
                'lease' => $lease, 'system' => true, 'details' => ['revocation_id' => $revocation->id],
            ]);
            $this->tell($lease, $revocation, AutopayRevocationNotification::APPLIED);
            $applied++;
        }

        return $applied;
    }

    /** Tells the customer and payment staff. A mail problem must never undo the request, so it is caught. */
    private function tell(LeaseAgreement $lease, AutopayRevocation $revocation, string $kind): void
    {
        try {
            $notification = new AutopayRevocationNotification($lease, $revocation, $kind);
            $lease->loadMissing('customer');
            $lease->customer?->notify($notification);
            Notification::send(PaymentStaff::recipients(), $notification);
        } catch (\Throwable $e) {
            report($e);
        }
    }
}
