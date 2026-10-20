<?php

namespace App\Notifications;

use App\Models\AutopayRevocation;
use App\Models\LeaseAgreement;
use App\Notifications\Concerns\BuildsMailFromArray;
use Illuminate\Notifications\Notification;

/**
 * A customer asked to turn AutoPay off, changed their mind, or AutoPay was switched off on the day (see AutopayRevocationService). The
 * customer gets a copy by email for their records, payment staff get an alert in the portal.
 */
class AutopayRevocationNotification extends Notification
{
    use BuildsMailFromArray;

    public const REQUESTED = 'requested';

    public const WITHDRAWN = 'withdrawn';

    public const APPLIED = 'applied';

    public function __construct(
        private readonly LeaseAgreement $lease,
        private readonly AutopayRevocation $revocation,
        private readonly string $kind,
    ) {}

    public function via(object $notifiable): array
    {
        return $notifiable->isStaff() ? ['database'] : ['database', 'mail'];
    }

    public function toArray(object $notifiable): array
    {
        $this->lease->loadMissing('customer', 'application');
        $effective = $this->revocation->effective_on->format('F j, Y');

        if ($notifiable->isStaff()) {
            $name = $this->lease->customer->name;
            [$title, $body] = match ($this->kind) {
                self::REQUESTED => ["AutoPay turn-off requested: {$name}", "The customer asked to turn AutoPay off. It keeps charging until {$effective} (three business days) and is then switched off by the system."],
                self::WITHDRAWN => ["AutoPay turn-off withdrawn: {$name}", 'The customer changed their mind: AutoPay stays on.'],
                default => ["AutoPay switched off: {$name}", 'AutoPay was switched off at the customer\'s request. They now pay each month from the Payments page.'],
            };

            return [
                'type' => 'payment',
                'title' => $title,
                'body' => $body,
                'action_url' => $this->lease->application ? "/admin/applications/{$this->lease->application->id}" : '/admin/payments',
            ];
        }

        [$title, $body] = match ($this->kind) {
            self::REQUESTED => [
                'You asked to turn AutoPay off',
                "AutoPay stays on until {$effective}, three business days after your request, so a payment that comes due before then is still charged automatically. From {$effective} you pay each monthly payment yourself from your Payments page, and a payment not paid by its due date can be charged a late fee as your Lease Agreement says. If you change your mind, you can keep AutoPay on from your Payments page until then.",
            ],
            self::WITHDRAWN => ['AutoPay stays on', 'You kept AutoPay on, so nothing changes: your monthly payments are still charged automatically.'],
            default => ['AutoPay is now off', 'AutoPay is off for your lease, as you asked. From now on you pay each monthly payment yourself from your Payments page.'],
        };

        return ['type' => 'payment', 'title' => $title, 'body' => $body, 'action_url' => '/customer/payments'];
    }
}
