<?php

namespace App\Notifications;

use App\Models\PaymentLink;
use App\Services\BillingClock;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * The email a payment link is sent in (client, Joel, 2026-10-19 checklist, section 6). It says what the payment is for, how much, until
 * when the link works, and carries the button. Sent to an address (the customer's, or another staff typed), so it is mail only.
 */
class PaymentLinkNotification extends Notification
{
    public function __construct(
        private readonly PaymentLink $link,
        private readonly string $url,
        private readonly ?string $name = null,
    ) {}

    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $amount = number_format((float) $this->link->amount, 2);
        $until = $this->link->expires_at->setTimezone(BillingClock::TIMEZONE)->format('F j, Y');

        return (new MailMessage)
            ->subject('Your payment link from Prostart Leasing')
            ->greeting($this->name ? "Hi {$this->name}," : 'Hello,')
            ->line("Here is the link to pay: **{$this->link->description}**.")
            ->line($this->link->kind === PaymentLink::KIND_PAYMENT
                ? "You can pay up to \${$amount} with it, by bank account or card. A card payment adds a small card fee, shown before you pay."
                : "You can pay \${$amount} toward your early purchase price with it, by bank account or card. A card payment adds a small card fee, shown before you pay.")
            ->line("The link works until {$until}. It is for you only, so please do not share it.")
            ->action('Pay now', $this->url)
            ->line('If you did not expect this email, you can ignore it. Nothing is charged until you press pay.');
    }
}
