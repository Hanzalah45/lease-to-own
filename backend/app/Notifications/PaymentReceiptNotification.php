<?php

namespace App\Notifications;

use App\Models\PaymentReceipt;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Carbon;

/**
 * The email a receipt is sent in (client, Joel, 2026-10-19 checklist, section 8), with the stored PDF copy attached. Sent to an address
 * (the customer's, or another staff typed when sending it again), so it is mail only; the in-app notice of the payment is a separate one.
 */
class PaymentReceiptNotification extends Notification
{
    public function __construct(
        private readonly PaymentReceipt $receipt,
        private readonly string $pdf,
        private readonly string $fileName,
        private readonly ?string $name = null,
    ) {}

    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $d = $this->receipt->details;
        $amount = number_format((float) $this->receipt->amount, 2);
        $on = Carbon::parse($d['paid_on'])->format('F j, Y');
        $refund = $this->receipt->kind === PaymentReceipt::KIND_REFUND;
        $balance = $d['balance'] ?? [];

        $mail = (new MailMessage)
            ->subject(($refund ? 'Your refund receipt ' : 'Your receipt ').$this->receipt->receipt_number.' from Prostart Leasing')
            ->greeting($this->name ? "Hi {$this->name}," : 'Hello,');

        if ($refund) {
            $mail->line("A refund of **\${$amount}** for your {$d['description']} was issued on {$on}.")
                ->line('It goes back to the card or bank account you paid with, and can take 5 to 10 business days to show on your statement.');
        } else {
            $mail->line("Thank you. We received **\${$amount}** for your {$d['description']} on {$on}. Paid by: {$d['method_label']}.");
            if (isset($balance['paid_in_full']) && ! $balance['paid_in_full']) {
                $mail->line('**$'.number_format((float) $balance['remaining'], 2).'** of this payment is still due.');
            }
        }

        return $mail
            ->line("Receipt {$this->receipt->receipt_number} is attached as a PDF. Please keep it for your records.")
            ->attachData($this->pdf, $this->fileName, ['mime' => 'application/pdf']);
    }
}
