<?php

namespace App\Notifications;

use App\Models\Application;
use App\Models\ApplicationInfoRequest;
use App\Notifications\Concerns\BuildsMailFromArray;
use App\Services\InfoRequestSigner;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Sent to the customer when an admin asks for more information — carries the
 * actual question, unlike the generic ApplicationStatusChangedNotification a
 * "needs_info" transition used to fall through to (real gap found
 * 2026-09-16: that one has no way to know the question text, and for a
 * pending guest customer it said "no action needed from you right now",
 * which was actively wrong here). A guest-originated customer has no usable
 * account yet, so they get the same signed-link pattern as
 * ContractSigner/BankVerificationSigner instead of a portal link — that link
 * is already absolute, unlike every other notification's action_url, so
 * toMail() is overridden rather than letting BuildsMailFromArray prefix it
 * with the frontend URL a second time.
 */
class ApplicationInfoRequestedNotification extends Notification
{
    use BuildsMailFromArray;

    public function __construct(private readonly Application $application, private readonly ApplicationInfoRequest $infoRequest) {}

    public function via(object $notifiable): array
    {
        return ['database', 'mail'];
    }

    private function hasUsableAccount(object $notifiable): bool
    {
        return $notifiable->status !== 'pending';
    }

    public function toArray(object $notifiable): array
    {
        return [
            'type' => 'application',
            'title' => "Prostart Leasing needs more information on application #{$this->application->id}",
            'body' => $this->infoRequest->request_text,
            'action_url' => $this->hasUsableAccount($notifiable) ? "/customer/applications/{$this->application->id}" : null,
        ];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $mail = $this->buildBaseMail($notifiable);

        return $this->hasUsableAccount($notifiable)
            ? $mail
            : $mail->action('Respond now', InfoRequestSigner::urlFor($notifiable, $this->application));
    }
}
