<?php

namespace App\Services;

use App\Models\AdditionalFundsEntry;
use App\Models\Payment;
use App\Models\PaymentRefund;
use App\Models\QuickbooksSyncSetting;
use Carbon\Carbon;
use InvalidArgumentException;

/**
 * Turns one collected payment into one balanced QuickBooks journal entry: everything received is debited to the "money
 * received" account, and each part of it is credited to the account the accountant chose for that kind of money (rent income,
 * sales tax, tracking fee, security deposit, late fee, extra payment, card fee). The parts come from PaymentReport::breakdown,
 * the same split the Reports page shows, so the books and the report cannot disagree.
 *
 * A journal entry rather than an invoice or sales receipt because the accounts are chosen directly: nothing has to be set up
 * in QuickBooks first (no items, no customers, no tax codes), and the sales tax lands exactly as the portal collected it
 * instead of being recalculated by QuickBooks.
 */
class QuickbooksJournal
{
    /** breakdown field => [settings account key, what the line says]. */
    private const PARTS = [
        'rent' => ['rent_income', 'Rent'],
        'ldw' => ['ldw_income', 'Loss damage waiver'],
        'sales_tax' => ['sales_tax', 'Sales tax'],
        'tracking_fees' => ['tracking_fee_income', 'Tracking fee'],
        'deposits' => ['security_deposit', 'Security deposit'],
        'late_fees' => ['late_fee_income', 'Late fee'],
        'extra_payments' => ['extra_payment', 'Extra payment toward the early purchase price'],
        'card_fees' => ['card_fee_income', 'Card fee'],
    ];

    public function __construct(private readonly PaymentReport $report) {}

    public static function docNumber(Payment $payment): string
    {
        return 'PL-'.$payment->id;
    }

    public static function reversalDocNumber(Payment $payment): string
    {
        return 'PL-R'.$payment->id;
    }

    /**
     * The journal entry for a collected payment, or null when there is nothing to record (a zero-amount payment).
     *
     * @return array<string, mixed>|null
     */
    public function forPayment(Payment $payment, QuickbooksSyncSetting $settings): ?array
    {
        $lines = $this->lines($payment, $settings);
        if ($lines === null) {
            return null;
        }

        return [
            'DocNumber' => self::docNumber($payment),
            'TxnDate' => $payment->paid_date->toDateString(),
            'PrivateNote' => "Prostart Leasing payment #{$payment->id} ({$payment->type}), paid {$payment->paid_date->toDateString()}",
            'Line' => $lines,
        ];
    }

    /**
     * The entry that cancels a synced payment that was later refunded or taken back: the same lines, debits and credits swapped.
     *
     * @return array<string, mixed>|null
     */
    public function reversalFor(Payment $payment, QuickbooksSyncSetting $settings, Carbon $date): ?array
    {
        $lines = $this->lines($payment, $settings);
        if ($lines === null) {
            return null;
        }

        foreach ($lines as &$line) {
            $detail = &$line['JournalEntryLineDetail'];
            $detail['PostingType'] = $detail['PostingType'] === 'Debit' ? 'Credit' : 'Debit';
            $line['Description'] = 'Reversal: '.$line['Description'];
        }
        unset($line, $detail);

        return [
            'DocNumber' => self::reversalDocNumber($payment),
            'TxnDate' => $date->toDateString(),
            'PrivateNote' => "Reverses Prostart Leasing payment #{$payment->id} ({$payment->type}): it was refunded or taken back",
            'Line' => $lines,
        ];
    }

    /** @return list<array<string, mixed>>|null */
    private function lines(Payment $payment, QuickbooksSyncSetting $settings): ?array
    {
        if (! $payment->paid_date) {
            throw new InvalidArgumentException("Payment #{$payment->id} has no paid date.");
        }

        $row = $this->report->breakdown($payment);
        $who = $this->who($row, $payment);

        $debit = $this->cents($row['total_collected']);
        if ($debit <= 0) {
            return null;
        }

        $credits = $this->parts($row, $debit, $settings, $who, "Payment #{$payment->id}", '');

        $lines = [$this->line('Debit', $this->account($settings, 'deposit_account'), $debit, "Received ({$this->methodLabel($row)}), {$who}")];
        foreach ($credits as $credit) {
            $lines[] = $this->line('Credit', $credit['account'], $credit['cents'], $credit['text']);
        }

        return $lines;
    }

    /**
     * The account and amount for each part of $split (a payment's breakdown, or the share of it a refund takes). The parts add up to
     * the total by construction; if a rounding step ever left a cent over or short, it goes on the largest part so the entry always
     * balances (QuickBooks refuses one that does not).
     *
     * @param  array<string, mixed>  $split
     * @return list<array{account: string, cents: int, text: string}>
     */
    private function parts(array $split, int $total, QuickbooksSyncSetting $settings, string $who, string $what, string $prefix): array
    {
        $parts = [];
        foreach (self::PARTS as $field => [$accountKey, $label]) {
            $cents = $this->cents($split[$field] ?? 0);
            if ($cents > 0) {
                $parts[] = ['account' => $this->account($settings, $accountKey), 'cents' => $cents, 'text' => "{$prefix}{$label}, {$who}"];
            }
        }

        $diff = $total - array_sum(array_column($parts, 'cents'));
        if ($diff !== 0) {
            if ($parts === []) {
                throw new InvalidArgumentException("{$what} has no part to record.");
            }
            $largest = 0;
            foreach ($parts as $i => $part) {
                if ($part['cents'] > $parts[$largest]['cents']) {
                    $largest = $i;
                }
            }
            $parts[$largest]['cents'] += $diff;
        }

        return $parts;
    }

    /**
     * The entry for money that went back to a customer (a refund, or a chargeback that was lost): what the payment's entry credited
     * is debited in the refund's share, and the money-received account is credited. Null when there is nothing to record.
     *
     * @return array<string, mixed>|null
     */
    public function forRefund(PaymentRefund $refund, QuickbooksSyncSetting $settings): ?array
    {
        $payment = $refund->payment;
        $row = $this->report->breakdown($payment);
        $who = $this->who($row, $payment);

        $credit = $this->cents($refund->amount);
        if ($credit <= 0) {
            return null;
        }

        $debits = $this->parts($this->report->refundBreakdown($refund), $credit, $settings, $who, "Refund #{$refund->id}", 'Refund: ');

        $lines = [$this->line('Credit', $this->account($settings, 'deposit_account'), $credit, "Refunded ({$this->methodLabel($row)}), {$who}")];
        foreach ($debits as $debit) {
            $lines[] = $this->line('Debit', $debit['account'], $debit['cents'], $debit['text']);
        }

        $date = $refund->refunded_at->setTimezone(BillingClock::TIMEZONE)->toDateString();
        $why = $refund->reason === PaymentRefund::REASON_CHARGEBACK ? 'a chargeback was lost' : 'it was refunded';

        return [
            'DocNumber' => self::refundDocNumber($refund),
            'TxnDate' => $date,
            'PrivateNote' => "Prostart Leasing refund #{$refund->id} of payment #{$payment->id} ({$payment->type}): {$why}",
            'Line' => $lines,
        ];
    }

    /**
     * The entry for Additional Funds applied to repairs: the money the customer paid toward the early purchase price, held as a
     * liability, becomes income that covers the repair. Debit the held-funds account, credit the repair income account.
     *
     * @return array<string, mixed>|null
     */
    public function forFundsApplied(AdditionalFundsEntry $entry, QuickbooksSyncSetting $settings): ?array
    {
        $cents = abs($this->cents($entry->amount));
        if ($cents <= 0) {
            return null;
        }

        $entry->loadMissing('leaseAgreement.customer:id,name');
        $name = trim(preg_replace('/[\x00-\x1F\x7F]+/u', ' ', (string) ($entry->leaseAgreement?->customer?->name ?? '')));
        $who = mb_substr($name === '' ? 'customer' : $name, 0, 100).", lease #{$entry->lease_agreement_id}";

        return [
            'DocNumber' => 'PL-A'.$entry->id,
            'TxnDate' => $entry->created_at->setTimezone(BillingClock::TIMEZONE)->toDateString(),
            'PrivateNote' => "Prostart Leasing Additional Funds applied to repairs (entry #{$entry->id}, lease #{$entry->lease_agreement_id})",
            'Line' => [
                $this->line('Debit', $this->account($settings, 'extra_payment'), $cents, "Additional Funds applied to repairs, {$who}"),
                $this->line('Credit', $this->account($settings, 'repair_income'), $cents, mb_substr('Repair costs recovered, '.$who.($entry->description ? ' ('.$entry->description.')' : ''), 0, 4000)),
            ],
        ];
    }

    public static function refundDocNumber(PaymentRefund $refund): string
    {
        return 'PL-F'.$refund->id;
    }

    /** @return array<string, mixed> */
    private function line(string $posting, string $accountId, int $cents, string $description): array
    {
        return [
            'DetailType' => 'JournalEntryLineDetail',
            'Amount' => round($cents / 100, 2),
            'Description' => $description,
            'JournalEntryLineDetail' => [
                'PostingType' => $posting,
                'AccountRef' => ['value' => $accountId],
            ],
        ];
    }

    private function account(QuickbooksSyncSetting $settings, string $key): string
    {
        return $settings->accountId($key)
            ?? throw new InvalidArgumentException("No QuickBooks account is chosen for: {$key}.");
    }

    private function cents(mixed $amount): int
    {
        return (int) round(((float) $amount) * 100);
    }

    /** @param array<string, mixed> $row */
    private function methodLabel(array $row): string
    {
        return match ($row['method']) {
            'ach' => 'bank account',
            'card' => 'card',
            'cash' => 'cash',
            'check' => 'check',
            'split' => 'split payment',
            null, '' => 'method not recorded',
            default => (string) $row['method'],
        };
    }

    /** @param array<string, mixed> $row */
    private function who(array $row, Payment $payment): string
    {
        $name = trim(preg_replace('/[\x00-\x1F\x7F]+/u', ' ', (string) ($row['customer'] ?? '')));
        $name = mb_substr($name === '' ? 'customer' : $name, 0, 100);

        return "{$name}, lease #{$payment->lease_agreement_id}";
    }
}
