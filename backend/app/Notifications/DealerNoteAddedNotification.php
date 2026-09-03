<?php

namespace App\Notifications;

use App\Models\DealerNote;
use Illuminate\Notifications\Notification;

/** Sent to other admins with application_review access when a note is posted on an application. */
class DealerNoteAddedNotification extends Notification
{
    public function __construct(private readonly DealerNote $note) {}

    public function via(object $notifiable): array
    {
        return ['database'];
    }

    public function toArray(object $notifiable): array
    {
        $this->note->loadMissing(['author:id,name', 'application.customer:id,name']);

        return [
            'type' => 'application',
            'title' => "{$this->note->author->name} added a note",
            'body' => "Application #{$this->note->application_id} ({$this->note->application->customer->name}): \"{$this->note->text}\"",
            'action_url' => "/admin/applications/{$this->note->application_id}",
        ];
    }
}
