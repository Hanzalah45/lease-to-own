<?php

namespace App\Notifications;

use App\Models\ApplicationInfoRequest;
use App\Notifications\Concerns\BuildsMailFromArray;
use Illuminate\Notifications\Notification;

/** Sent to every admin/super admin with application-review access when a customer replies to a "needs info" request. */
class ApplicationInfoProvidedNotification extends Notification
{
    use BuildsMailFromArray;

    public function __construct(private readonly ApplicationInfoRequest $infoRequest) {}

    public function via(object $notifiable): array
    {
        return ['database', 'mail'];
    }

    public function toArray(object $notifiable): array
    {
        $this->infoRequest->loadMissing('application.customer');
        $application = $this->infoRequest->application;

        $body = match (true) {
            $this->infoRequest->reply_text && $this->infoRequest->reply_document_path
                => "Application #{$application->id}: \"{$this->infoRequest->reply_text}\" (also attached an updated ID document)",
            (bool) $this->infoRequest->reply_text
                => "Application #{$application->id}: \"{$this->infoRequest->reply_text}\"",
            default => "Application #{$application->id} uploaded an updated ID document, back under review",
        };

        return [
            'type' => 'application',
            'title' => "{$application->customer->name} responded to your info request",
            'body' => $body,
            'action_url' => "/admin/applications/{$application->id}",
        ];
    }
}
