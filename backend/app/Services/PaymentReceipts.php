<?php

namespace App\Services;

use App\Models\Payment;
use App\Models\PaymentPart;
use App\Models\PaymentReceipt;
use App\Models\PaymentReceiptSend;
use App\Models\PaymentRefund;
use App\Models\User;
use App\Notifications\PaymentReceiptNotification;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Throwable;

/**
 * Formal receipts (client, Joel, 2026-10-19 checklist, section 8). A numbered receipt is written once for money that came in (a whole
 * payment, or each piece of a payment paid in parts) and once for each refund, emailed to the customer with a PDF copy attached, and
 * the PDF is kept on the private disk as the copy for audit. Staff can send any receipt again, and every send is logged.
 *
 * Nothing here is allowed to get in the way of the money it describes: issuing and sending are caught and reported, and the caller
 * falls back to the ordinary notice when this says the receipt was not emailed.
 */
class PaymentReceipts
{
    public function __construct(private readonly PaymentReport $report) {}

    public static function make(): self
    {
        return app(self::class);
    }

    private const TYPE_LABEL = [
        Payment::TYPE_RENTAL => 'Monthly payment',
        Payment::TYPE_LATE_FEE => 'Late fee',
        Payment::TYPE_DEPOSIT => 'Security deposit',
        Payment::TYPE_PICKUP_BALANCE => 'Tracking fee and first month',
        Payment::TYPE_EXTRA_PAYMENT => 'Extra payment toward the early purchase price',
    ];

    private const METHOD_LABEL = [
        'ach' => 'Bank account',
        'card' => 'Card',
        'cash' => 'Cash',
        'check' => 'Check',
        'other' => 'Another way',
        'split' => 'More than one method',
    ];

    // ---- when money lands or goes back ----

    /**
     * A payment became paid: write the receipt of what came in (one per piece when it was paid in parts) and email it. Returns true when
     * the customer was emailed their receipt, so the caller does not send its own message as well.
     */
    public static function onPaid(Payment $payment, bool $autoSend = true): bool
    {
        try {
            $service = self::make();
            $payment->loadMissing('leaseAgreement.customer.customerProfile', 'leaseAgreement.payments');
            // The first monthly row "Mark Delivered" records alongside the tracking fee and first month moved no money of its own.
            if ($service->report->isFirstMonthMirror($payment)) {
                return false;
            }

            $latest = null;
            $parts = $payment->parts()->where('status', PaymentPart::STATUS_PAID)->orderBy('id')->get();
            if ($parts->isNotEmpty()) {
                foreach ($parts as $part) {
                    [$latest, $created] = $service->issueForPart($part, $payment);
                    if ($created && $autoSend) {
                        $service->autoSend($latest);
                    }
                }
            } else {
                [$latest, $created] = $service->issueForPayment($payment);
                if ($created && $autoSend) {
                    $service->autoSend($latest);
                }
            }

            return $latest !== null && $autoSend && $service->wasAutoMailed($latest);
        } catch (Throwable $e) {
            report($e);

            return false;
        }
    }

    /** One piece of a payment came in: its receipt, emailed. True when the customer was emailed it. */
    public static function onPartPaid(PaymentPart $part): bool
    {
        try {
            $service = self::make();
            $payment = Payment::with('leaseAgreement.customer.customerProfile')->findOrFail($part->payment_id);
            [$receipt, $created] = $service->issueForPart($part, $payment);
            if ($created) {
                $service->autoSend($receipt);
            }

            return $service->wasAutoMailed($receipt);
        } catch (Throwable $e) {
            report($e);

            return false;
        }
    }

    /** A refund was made: its receipt, emailed. True when the customer was emailed it. A lost chargeback is the customer's own bank's doing and gets none. */
    public static function onRefund(PaymentRefund $refund): bool
    {
        try {
            if ($refund->reason === PaymentRefund::REASON_CHARGEBACK) {
                return false;
            }
            $service = self::make();
            [$receipt, $created] = $service->issueForRefund($refund);
            if ($created) {
                $service->autoSend($receipt);
            }

            return $service->wasAutoMailed($receipt);
        } catch (Throwable $e) {
            report($e);

            return false;
        }
    }

    // ---- writing a receipt ----

    /** @return array{0: PaymentReceipt, 1: bool} the receipt, and whether it was written now */
    public function issueForPayment(Payment $payment): array
    {
        $payment->loadMissing('leaseAgreement.customer', 'leaseAgreement.equipmentUnit');
        $lease = $payment->leaseAgreement;
        $split = $this->report->split($lease, $payment->type, (float) $payment->amount, (float) $payment->card_fee_amount);

        return $this->issue("payment:{$payment->id}", PaymentReceipt::KIND_PAYMENT, $payment, null, null, $split['total_collected'], [
            'description' => $this->describe($payment),
            'method' => $payment->method,
            'method_label' => self::METHOD_LABEL[$payment->method] ?? 'Not recorded',
            'paid_on' => $payment->paid_date?->toDateString() ?? BillingClock::todayDate(),
            'lines' => $this->lines($split, $payment->type),
            'total' => $split['total_collected'],
            'balance' => ['price' => round((float) $payment->amount, 2), 'paid_to_date' => round((float) $payment->amount, 2), 'remaining' => 0.0, 'paid_in_full' => true],
        ]);
    }

    /** @return array{0: PaymentReceipt, 1: bool} */
    public function issueForPart(PaymentPart $part, ?Payment $payment = null): array
    {
        $payment ??= Payment::findOrFail($part->payment_id);
        $payment->loadMissing('leaseAgreement.customer', 'leaseAgreement.equipmentUnit');
        $lease = $payment->leaseAgreement;
        $split = $this->report->split($lease, $payment->type, (float) $part->amount, (float) $part->card_fee_amount);
        $paid = round((float) $payment->parts()->where('status', PaymentPart::STATUS_PAID)->sum('amount'), 2);
        $remaining = max(0.0, round((float) $payment->amount - $paid, 2));

        return $this->issue("part:{$part->id}", PaymentReceipt::KIND_PAYMENT, $payment, $part, null, $split['total_collected'], [
            'description' => $this->describe($payment),
            'method' => $part->method,
            'method_label' => self::METHOD_LABEL[$part->method] ?? 'Not recorded',
            'paid_on' => $part->paid_date?->toDateString() ?? BillingClock::todayDate(),
            'lines' => $this->lines($split, $payment->type),
            'total' => $split['total_collected'],
            'balance' => ['price' => round((float) $payment->amount, 2), 'paid_to_date' => min($paid, round((float) $payment->amount, 2)), 'remaining' => $remaining, 'paid_in_full' => $remaining < 0.005],
        ]);
    }

    /** @return array{0: PaymentReceipt, 1: bool} */
    public function issueForRefund(PaymentRefund $refund): array
    {
        $refund->loadMissing('part', 'payment.leaseAgreement.customer', 'payment.leaseAgreement.equipmentUnit');
        $payment = $refund->payment;
        $split = $this->report->refundBreakdown($refund);
        $method = $refund->part?->method ?? $payment->method;
        $refundedSoFar = round((float) PaymentRefund::where('payment_id', $payment->id)->where('status', PaymentRefund::STATUS_SUCCEEDED)->where('id', '<=', $refund->id)->sum('amount'), 2);

        return $this->issue("refund:{$refund->id}", PaymentReceipt::KIND_REFUND, $payment, null, $refund, $split['total_collected'], [
            'description' => $this->describe($payment),
            'method' => $method,
            // Where the money went. A refund made outside the portal went back the way staff and the customer agreed.
            'method_label' => $refund->source === PaymentRefund::SOURCE_MANUAL ? 'Returned by staff outside the portal' : (self::METHOD_LABEL[$method] ?? 'The way it was paid'),
            'paid_on' => ($refund->refunded_at ?? now())->setTimezone(BillingClock::TIMEZONE)->toDateString(),
            'lines' => $this->lines($split, $payment->type),
            'total' => $split['total_collected'],
            'balance' => [
                'price' => round((float) $payment->amount + (float) $payment->card_fee_amount, 2),
                'refunded_to_date' => $refundedSoFar,
            ],
        ]);
    }

    /** @return array{0: PaymentReceipt, 1: bool} */
    private function issue(string $sourceKey, string $kind, Payment $payment, ?PaymentPart $part, ?PaymentRefund $refund, float $amount, array $body): array
    {
        $existing = PaymentReceipt::where('source_key', $sourceKey)->first();
        if ($existing) {
            return [$existing, false];
        }

        $lease = $payment->leaseAgreement;
        $details = $body + [
            'customer' => ['name' => $lease->customer?->name, 'email' => $lease->customer?->email],
            'lease_id' => $lease->id,
            'equipment' => $lease->equipmentUnit ? trim($lease->equipmentUnit->model.($lease->equipmentUnit->serial_number ? ' (serial '.$lease->equipmentUnit->serial_number.')' : '')) : null,
        ];

        try {
            $receipt = DB::transaction(function () use ($sourceKey, $kind, $lease, $payment, $part, $refund, $amount, $details) {
                $row = PaymentReceipt::create([
                    'source_key' => $sourceKey,
                    'kind' => $kind,
                    'lease_agreement_id' => $lease->id,
                    'payment_id' => $payment->id,
                    'payment_part_id' => $part?->id,
                    'payment_refund_id' => $refund?->id,
                    'amount' => $amount,
                    'details' => $details,
                    'issued_at' => now(),
                ]);
                $row->update(['receipt_number' => PaymentReceipt::formatNumber($row->id)]);

                return $row;
            });
        } catch (UniqueConstraintViolationException) {
            // The same money was receipted by another request a moment ago: that receipt is the one.
            return [PaymentReceipt::where('source_key', $sourceKey)->firstOrFail(), false];
        }

        // The stored copy is made outside the transaction: a PDF problem must never lose the receipt.
        try {
            $this->storeCopy($receipt);
        } catch (Throwable $e) {
            report($e);
        }

        return [$receipt->fresh(), true];
    }

    /** "Monthly payment due October 10, 2026". */
    private function describe(Payment $payment): string
    {
        $label = self::TYPE_LABEL[$payment->type] ?? 'Payment';

        return $payment->type === Payment::TYPE_EXTRA_PAYMENT || ! $payment->due_date ? $label : $label.' due '.$payment->due_date->format('F j, Y');
    }

    /**
     * What an amount is made of, in words, from the report's split. The lines add up to the total on the receipt.
     *
     * @param  array<string, float>  $split
     * @return list<array{label: string, amount: float}>
     */
    private function lines(array $split, string $type): array
    {
        $lines = [];
        $add = function (string $label, float $amount) use (&$lines) {
            if ($amount > 0.004) {
                $lines[] = ['label' => $label, 'amount' => round($amount, 2)];
            }
        };

        [$rentLabel, $ldwLabel] = $type === Payment::TYPE_PICKUP_BALANCE ? ['First month rent', 'First month loss damage waiver'] : ['Rent', 'Loss damage waiver'];
        $add($rentLabel, $split['rent']);
        $add($ldwLabel, $split['ldw']);
        $add('Tracking device fee', $split['tracking_fees']);
        $add('Security deposit', $split['deposits']);
        $add('Late fee', $split['late_fees']);
        $add('Extra payment toward the early purchase price', $split['extra_payments']);
        $add('Sales tax', $split['sales_tax']);
        $add('Card fee', $split['card_fees']);

        return $lines;
    }

    // ---- the stored copy ----

    /** Renders the receipt to a PDF and keeps it on the private disk, with its fingerprint. */
    public function storeCopy(PaymentReceipt $receipt): string
    {
        $receipt->loadMissing('leaseAgreement');
        // Only the letters the receipt uses go into the file: embedding the whole font made a one page receipt 850 KB.
        $bytes = Pdf::setOption(['enable_font_subsetting' => true])->loadView('pdf.receipt', [
            'receipt' => $receipt,
            'd' => $receipt->details,
            'companyPhone' => config('company.phone'),
            'companyAddress' => config('company.address'),
        ])->output();

        $path = sprintf('receipts/%s/%s.pdf', $receipt->issued_at->format('Y'), $receipt->receipt_number);
        Storage::disk('local')->put($path, $bytes);
        $receipt->update(['pdf_path' => $path, 'pdf_sha256' => hash('sha256', $bytes)]);

        return $bytes;
    }

    /** The stored copy; made again from what the receipt says if the file is gone. */
    public function copyOf(PaymentReceipt $receipt): string
    {
        if ($receipt->pdf_path && Storage::disk('local')->exists($receipt->pdf_path)) {
            return Storage::disk('local')->get($receipt->pdf_path);
        }

        return $this->storeCopy($receipt);
    }

    public function fileName(PaymentReceipt $receipt): string
    {
        return "Receipt-{$receipt->receipt_number}.pdf";
    }

    // ---- sending ----

    /** Emails the receipt to the customer when they take payment emails. Returns whether it was sent. */
    public function autoSend(PaymentReceipt $receipt): bool
    {
        $receipt->loadMissing('leaseAgreement.customer.customerProfile');
        $customer = $receipt->leaseAgreement->customer;
        if (! $customer?->email || ! ($customer->customerProfile?->payment_reminder_emails ?? true)) {
            return false;
        }

        return $this->send($receipt, $customer->email, PaymentReceiptSend::TRIGGER_AUTO)->outcome === PaymentReceiptSend::OUTCOME_SENT;
    }

    /** True when the receipt was emailed to the customer on its own when the money landed. */
    public function wasAutoMailed(PaymentReceipt $receipt): bool
    {
        return $receipt->sends()->where('trigger', PaymentReceiptSend::TRIGGER_AUTO)->where('outcome', PaymentReceiptSend::OUTCOME_SENT)->exists();
    }

    /** Sends the stored copy to an address and logs it. A mail problem is written down, never thrown. */
    public function send(PaymentReceipt $receipt, string $email, string $trigger, ?User $by = null): PaymentReceiptSend
    {
        $error = null;
        try {
            $receipt->loadMissing('leaseAgreement.customer');
            Notification::route('mail', $email)->notify(new PaymentReceiptNotification(
                $receipt,
                $this->copyOf($receipt),
                $this->fileName($receipt),
                $receipt->leaseAgreement->customer?->name,
            ));
        } catch (Throwable $e) {
            report($e);
            $error = Str::limit($e->getMessage(), 300, '');
        }

        $send = $receipt->sends()->create([
            'email' => $email,
            'trigger' => $trigger,
            'sent_by' => $by?->id,
            'outcome' => $error ? PaymentReceiptSend::OUTCOME_FAILED : PaymentReceiptSend::OUTCOME_SENT,
            'error' => $error,
            'sent_at' => now(),
        ]);

        if ($trigger === PaymentReceiptSend::TRIGGER_MANUAL) {
            AuditLog::record('receipt.resent', sprintf('Receipt %s was emailed again to %s%s.', $receipt->receipt_number, $email, $error ? ', but the mail service refused it' : ''), [
                'lease' => $receipt->leaseAgreement, 'payment' => $receipt->payment, 'amount' => $receipt->amount, 'actor' => $by, 'details' => ['receipt_id' => $receipt->id, 'email' => $email],
            ]);
        }

        return $send;
    }

    /** For a receipt shown on a screen: its date in the business's own time zone. */
    public static function issuedDate(PaymentReceipt $receipt): Carbon
    {
        return $receipt->issued_at->setTimezone(BillingClock::TIMEZONE);
    }
}
