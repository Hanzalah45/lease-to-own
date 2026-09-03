<?php

namespace App\Notifications;

use App\Models\Contract;
use Illuminate\Notifications\Notification;

/**
 * Sent to contract_generation admins when a signed contract's PDF fails to
 * render — signing itself still succeeds (ContractPdfService::ensure()
 * retries on the next download attempt), so this is a heads-up rather than
 * a blocker, but a repeated failure would otherwise only ever surface in
 * the server log.
 */
class ContractPdfGenerationFailedNotification extends Notification
{
    public function __construct(private readonly Contract $contract) {}

    public function via(object $notifiable): array
    {
        return ['database'];
    }

    public function toArray(object $notifiable): array
    {
        $this->contract->loadMissing('leaseAgreement.equipmentUnit');
        $model = $this->contract->leaseAgreement->equipmentUnit?->model ?? 'a lease';

        return [
            'type' => 'contract_pdf_failed',
            'title' => 'Contract PDF failed to generate',
            'body' => "The signed agreement PDF for {$model} could not be generated. It will retry automatically on the next download attempt.",
            'action_url' => "/admin/applications/{$this->contract->leaseAgreement->application_id}/contract",
        ];
    }
}
