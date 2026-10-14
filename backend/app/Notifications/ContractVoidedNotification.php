<?php

namespace App\Notifications;

use App\Models\Contract;
use App\Notifications\Concerns\BuildsMailFromArray;
use Illuminate\Notifications\Notification;

/**
 * Asks the customer to review and sign again after their signed contract was voided. Sent when a
 * mower change re-prices the lease, and when an admin voids a signature and chooses to notify them.
 */
class ContractVoidedNotification extends Notification
{
    use BuildsMailFromArray;

    public function __construct(private readonly Contract $contract) {}

    public function via(object $notifiable): array
    {
        return ['database', 'mail'];
    }

    public function toArray(object $notifiable): array
    {
        $this->contract->loadMissing('leaseAgreement.equipmentUnit');
        $model = $this->contract->leaseAgreement->equipmentUnit?->model ?? 'your lease';

        return [
            'type' => 'contract_voided',
            'title' => 'Your signature was voided',
            'body' => "Your signed agreement for {$model} was voided: \"{$this->contract->void_reason}\". Please review and sign again.",
            'action_url' => '/customer/contracts',
        ];
    }
}
