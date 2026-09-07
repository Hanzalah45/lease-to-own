<?php

namespace App\Notifications;

use App\Models\LeaseAgreement;
use App\Notifications\Concerns\BuildsMailFromArray;
use App\Services\ContractPdfService;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Facades\Storage;

/** Sent to the customer and staff once a lease's contract is e-signed. */
class ContractSignedNotification extends Notification
{
    use BuildsMailFromArray;

    public function __construct(private readonly LeaseAgreement $lease) {}

    public function via(object $notifiable): array
    {
        return ['database', 'mail'];
    }

    /** Overrides the trait default to attach the signed PDF, per the plan's "with signed copy" requirement. */
    public function toMail(object $notifiable): MailMessage
    {
        $mail = $this->buildBaseMail($notifiable);

        $contract = $this->lease->loadMissing('contract')->contract;
        if ($contract) {
            $path = ContractPdfService::ensure($contract);
            $mail->attach(Storage::disk('local')->path($path), [
                'as' => 'signed-lease-agreement.pdf',
                'mime' => 'application/pdf',
            ]);
        }

        return $mail;
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
