<?php

namespace App\Notifications;

use App\Models\LeaseAgreement;
use Illuminate\Notifications\Notification;

/** Sent to the customer and staff once a lease's contract is e-signed. */
class ContractSignedNotification extends Notification
{
    public function __construct(private readonly LeaseAgreement $lease) {}

    public function via(object $notifiable): array
    {
        return ['database'];
    }

    public function toArray(object $notifiable): array
    {
        // Sent to both the signing customer and staff — each needs a different URL.
        $actionUrl = $notifiable->isStaff()
            ? "/admin/applications/{$this->lease->application_id}/contract"
            : '/customer/contracts';

        return [
            'type' => 'contract_signed',
            'title' => 'Contract signed',
            'body' => sprintf('The lease agreement for %s has been signed.', $this->lease->equipmentUnit?->model ?? 'this lease'),
            'action_url' => $actionUrl,
        ];
    }
}
