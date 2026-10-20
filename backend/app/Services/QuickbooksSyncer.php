<?php

namespace App\Services;

use App\Models\AdditionalFundsEntry;
use App\Models\Payment;
use App\Models\PaymentRefund;
use App\Models\QuickbooksConnection;
use App\Models\QuickbooksSyncSetting;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use InvalidArgumentException;

/**
 * Sends every payment that was collected to QuickBooks as a journal entry, once.
 *
 * Safe to run any number of times and from several places at once (the scheduler, the "Send now" button): a lock keeps two
 * runs from overlapping, every entry carries the payment's number as its DocNumber, and a payment whose earlier try may have
 * reached QuickBooks without the answer coming back is looked up by that number before it is created again. A payment that
 * was sent and later refunded or taken back gets a reversing entry.
 */
class QuickbooksSyncer
{
    /** Refusals by QuickBooks a payment may collect before it is parked as failed for a person to look at. */
    public const MAX_ATTEMPTS = 3;

    public const BATCH = 100;

    public const STATUS_SYNCED = 'synced';

    public const STATUS_SKIPPED = 'skipped';

    public const STATUS_FAILED = 'failed';

    public function __construct(
        private readonly QuickbooksClient $client,
        private readonly QuickbooksJournal $journal,
        private readonly PaymentReport $report,
    ) {}

    /**
     * @return array{sent: int, skipped: int, failed: int, reversed: int, stopped: ?string}
     */
    public function run(int $limit = self::BATCH): array
    {
        $result = ['sent' => 0, 'skipped' => 0, 'failed' => 0, 'reversed' => 0, 'stopped' => null];
        $settings = QuickbooksSyncSetting::current();

        if (! $settings->isReady()) {
            $result['stopped'] = $settings->sync_enabled ? 'Choose a start date and an account for every kind of money first.' : 'Sending to QuickBooks is switched off.';

            return $result;
        }

        $connection = QuickbooksConnection::first();
        if (! $connection || $connection->isRefreshTokenExpired()) {
            $result['stopped'] = 'QuickBooks is not connected. Reconnect it on the Settings page.';
            $settings->update(['last_error' => $result['stopped']]);

            return $result;
        }

        $lock = Cache::lock('quickbooks-sync', 600);
        if (! $lock->get()) {
            $result['stopped'] = 'A send is already running.';

            return $result;
        }

        try {
            $result['stopped'] = $this->sendPending($settings, $limit, $result)
                ?? $this->sendRefunds($settings, $limit, $result)
                ?? $this->sendFundsApplied($settings, $limit, $result)
                ?? $this->reverseTakenBack($settings, $limit, $result);
        } finally {
            $lock->release();
        }

        $settings->update(['last_synced_at' => now(), 'last_error' => $result['stopped']]);

        return $result;
    }

    /** Puts every failed payment back in line to be sent again (the "Try again" button). */
    public function retryFailed(): int
    {
        $reset = [
            'quickbooks_sync_status' => null,
            'quickbooks_sync_attempts' => 0,
            'quickbooks_sync_error' => null,
        ];

        return Payment::where('quickbooks_sync_status', self::STATUS_FAILED)->update($reset)
            + PaymentRefund::where('quickbooks_sync_status', self::STATUS_FAILED)->update($reset)
            + AdditionalFundsEntry::where('quickbooks_sync_status', self::STATUS_FAILED)->update($reset);
    }

    /**
     * @param  array<string, mixed>  $result
     * @return string|null why the run had to stop early, or null when it got through them all
     */
    private function sendPending(QuickbooksSyncSetting $settings, int $limit, array &$result): ?string
    {
        // A payment that was refunded is still sent as the payment it was: its refunds are entries of their own, so the books show
        // both the money that came in and the money that went back.
        $pending = Payment::with('leaseAgreement.customer:id,name,email')
            ->where(fn ($q) => $q->where('status', Payment::STATUS_PAID)->orWhere(fn ($r) => $r->where('status', Payment::STATUS_REFUNDED)->whereHas('refunds', fn ($f) => $f->where('status', PaymentRefund::STATUS_SUCCEEDED))))
            ->whereNull('quickbooks_sync_status')
            ->whereDate('paid_date', '>=', $settings->sync_from->toDateString())
            ->orderBy('paid_date')
            ->orderBy('id')
            ->limit($limit)
            ->get();

        foreach ($pending as $payment) {
            try {
                $outcome = $this->send($payment, $settings);
                $result[$outcome === self::STATUS_SKIPPED ? 'skipped' : 'sent']++;
            } catch (QuickbooksException $e) {
                if ($e->authFailed || $e->transient) {
                    // Not this payment's fault: leave the rest in line and stop, the next run picks up from here.
                    $payment->update(['quickbooks_sync_error' => $e->getMessage()]);

                    return $e->getMessage();
                }
                $this->recordRefusal($payment, $e->getMessage());
                $result['failed']++;
            } catch (InvalidArgumentException $e) {
                $this->recordRefusal($payment, $e->getMessage());
                $result['failed']++;
            }
        }

        return null;
    }

    /**
     * @param  array<string, mixed>  $result
     */
    private function reverseTakenBack(QuickbooksSyncSetting $settings, int $limit, array &$result): ?string
    {
        $taken = Payment::with('leaseAgreement.customer:id,name,email')
            ->whereNotNull('quickbooks_record_id')
            ->whereNull('quickbooks_reversal_id')
            ->where('status', '!=', Payment::STATUS_PAID)
            // A payment refunded through the portal is taken back by its refund entries, never by a second, full reversal.
            ->whereDoesntHave('refunds', fn ($f) => $f->where('status', PaymentRefund::STATUS_SUCCEEDED))
            ->where(fn ($q) => $q->whereNull('quickbooks_sync_status')->orWhere('quickbooks_sync_status', '!=', self::STATUS_FAILED))
            ->orderBy('id')
            ->limit($limit)
            ->get();

        foreach ($taken as $payment) {
            try {
                $entry = $this->journal->reversalFor($payment, $settings, BillingClock::today());
                if ($entry === null) {
                    continue;
                }
                $id = $this->entryId($payment, $entry, 'rev');
                $payment->update(['quickbooks_reversal_id' => $id, 'quickbooks_sync_error' => null]);
                $result['reversed']++;
            } catch (QuickbooksException $e) {
                if ($e->authFailed || $e->transient) {
                    $payment->update(['quickbooks_sync_error' => $e->getMessage()]);

                    return $e->getMessage();
                }
                $this->recordRefusal($payment, 'The reversal was refused: '.$e->getMessage());
                $result['failed']++;
            } catch (InvalidArgumentException $e) {
                $this->recordRefusal($payment, 'The reversal could not be built: '.$e->getMessage());
                $result['failed']++;
            }
        }

        return null;
    }

    /**
     * Sends the refunds that went through (and lost chargebacks) as entries of their own. A refund can only be booked once the
     * payment it refunds is in QuickBooks; one whose payment was never going to be sent (it is older than the start day) is skipped.
     *
     * @param  array<string, mixed>  $result
     * @return string|null why the run had to stop early, or null when it got through them all
     */
    private function sendRefunds(QuickbooksSyncSetting $settings, int $limit, array &$result): ?string
    {
        $refunds = PaymentRefund::with('payment.leaseAgreement.customer:id,name,email')
            ->where('status', PaymentRefund::STATUS_SUCCEEDED)
            ->whereNull('quickbooks_sync_status')
            ->orderBy('refunded_at')
            ->orderBy('id')
            ->limit($limit)
            ->get();

        foreach ($refunds as $refund) {
            $payment = $refund->payment;
            if (! $payment->quickbooks_record_id) {
                $neverSent = $payment->quickbooks_sync_status === self::STATUS_SKIPPED
                    || ! $payment->paid_date
                    || $payment->paid_date->lt($settings->sync_from);
                if ($neverSent) {
                    $this->markSkipped($refund, 'The payment it refunds was never sent to QuickBooks.');
                    $result['skipped']++;
                }

                // Otherwise the payment is still waiting its turn: the refund follows it.
                continue;
            }

            try {
                $entry = $this->journal->forRefund($refund, $settings);
                if ($entry === null) {
                    $this->markSkipped($refund, 'Nothing to record: the amount is zero.');
                    $result['skipped']++;

                    continue;
                }
                $id = $this->entryId($refund, $entry, 'ref');
                $refund->update([
                    'quickbooks_record_id' => $id,
                    'quickbooks_sync_status' => self::STATUS_SYNCED,
                    'quickbooks_synced_at' => now(),
                    'quickbooks_sync_error' => null,
                ]);
                $result['sent']++;
            } catch (QuickbooksException $e) {
                if ($e->authFailed || $e->transient) {
                    $refund->update(['quickbooks_sync_error' => $e->getMessage()]);

                    return $e->getMessage();
                }
                $this->recordRefusal($refund, $e->getMessage());
                $result['failed']++;
            } catch (InvalidArgumentException $e) {
                $this->recordRefusal($refund, $e->getMessage());
                $result['failed']++;
            }
        }

        return null;
    }

    /**
     * Sends Additional Funds applied to repairs as entries of their own: the money was held for the customer and is now income. One
     * from before the start day is skipped, because the extra payment it comes out of was never sent either.
     *
     * @param  array<string, mixed>  $result
     * @return string|null why the run had to stop early, or null when it got through them all
     */
    private function sendFundsApplied(QuickbooksSyncSetting $settings, int $limit, array &$result): ?string
    {
        $entries = AdditionalFundsEntry::with('leaseAgreement.customer:id,name')
            ->where('kind', AdditionalFundsEntry::KIND_REPAIRS)
            ->whereNull('quickbooks_sync_status')
            ->orderBy('id')
            ->limit($limit)
            ->get();

        foreach ($entries as $entry) {
            if ($entry->created_at->setTimezone(BillingClock::TIMEZONE)->toDateString() < $settings->sync_from->toDateString()) {
                $this->markSkipped($entry, 'Applied before the start day, so the extra payment it comes out of was not sent either.');
                $result['skipped']++;

                continue;
            }

            try {
                $built = $this->journal->forFundsApplied($entry, $settings);
                if ($built === null) {
                    $this->markSkipped($entry, 'Nothing to record: the amount is zero.');
                    $result['skipped']++;

                    continue;
                }
                $id = $this->entryId($entry, $built, 'fnd');
                $entry->update([
                    'quickbooks_record_id' => $id,
                    'quickbooks_sync_status' => self::STATUS_SYNCED,
                    'quickbooks_synced_at' => now(),
                    'quickbooks_sync_error' => null,
                ]);
                $result['sent']++;
            } catch (QuickbooksException $e) {
                if ($e->authFailed || $e->transient) {
                    $entry->update(['quickbooks_sync_error' => $e->getMessage()]);

                    return $e->getMessage();
                }
                $this->recordRefusal($entry, $e->getMessage());
                $result['failed']++;
            } catch (InvalidArgumentException $e) {
                $this->recordRefusal($entry, $e->getMessage());
                $result['failed']++;
            }
        }

        return null;
    }

    /** @return string the status the payment ended in */
    private function send(Payment $payment, QuickbooksSyncSetting $settings): string
    {
        // The first monthly row "Mark Delivered" records next to a tracking fee and first month charge is only its mirror.
        if ($this->report->isFirstMonthMirror($payment)) {
            $this->markSkipped($payment, 'Counted inside the tracking fee and first month charge.');

            return self::STATUS_SKIPPED;
        }

        $entry = $this->journal->forPayment($payment, $settings);
        if ($entry === null) {
            $this->markSkipped($payment, 'Nothing to record: the amount is zero.');

            return self::STATUS_SKIPPED;
        }

        $id = $this->entryId($payment, $entry, 'pay');
        $payment->update([
            'quickbooks_record_id' => $id,
            'quickbooks_sync_status' => self::STATUS_SYNCED,
            'quickbooks_synced_at' => now(),
            'quickbooks_sync_error' => null,
        ]);

        return self::STATUS_SYNCED;
    }

    /**
     * The id of the entry in QuickBooks, creating it only when it is not there already. An earlier try that may have reached
     * QuickBooks without its answer coming back is told apart by the payment having an error or a counted refusal on record.
     *
     * @param  array<string, mixed>  $entry
     */
    private function entryId(Payment|PaymentRefund|AdditionalFundsEntry $payment, array $entry, string $kind): string
    {
        if ($payment->quickbooks_sync_error || $payment->quickbooks_sync_attempts > 0) {
            $found = $this->client->query('JournalEntry', "select * from JournalEntry where DocNumber = '{$entry['DocNumber']}'");
            if ($found !== []) {
                return (string) $found[0]['Id'];
            }
        }

        $created = $this->client->create('JournalEntry', $entry, "pl-{$kind}-{$payment->id}-{$payment->quickbooks_sync_attempts}");
        if (empty($created['Id'])) {
            throw new QuickbooksException('QuickBooks accepted the entry but did not say its number.', transient: true);
        }

        return (string) $created['Id'];
    }

    private function markSkipped(Payment|PaymentRefund|AdditionalFundsEntry $payment, string $why): void
    {
        $payment->update([
            'quickbooks_sync_status' => self::STATUS_SKIPPED,
            'quickbooks_synced_at' => now(),
            'quickbooks_sync_error' => $why,
        ]);
    }

    /** QuickBooks (or the entry itself) was refused: count it, and park the payment once it keeps happening. */
    private function recordRefusal(Payment|PaymentRefund|AdditionalFundsEntry $payment, string $message): void
    {
        $attempts = $payment->quickbooks_sync_attempts + 1;
        Log::warning('QuickBooks sync of a payment was refused.', ['payment_id' => $payment instanceof PaymentRefund ? $payment->payment_id : $payment->id, 'refund_id' => $payment instanceof PaymentRefund ? $payment->id : null, 'attempts' => $attempts, 'message' => $message]);

        $payment->update([
            'quickbooks_sync_attempts' => $attempts,
            'quickbooks_sync_error' => $message,
            'quickbooks_sync_status' => $attempts >= self::MAX_ATTEMPTS ? self::STATUS_FAILED : null,
        ]);
    }
}
