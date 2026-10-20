<?php

namespace App\Notifications;

use App\Models\EmailLog;
use Illuminate\Notifications\Notification;

/**
 * Tells staff in the portal that an email did not reach its person: it bounced for good (a mistyped or closed address) or was marked as
 * spam. Portal notice only: an email about an email that did not arrive would have the same problem.
 */
class EmailBouncedNotification extends Notification
{
    public function __construct(private readonly EmailLog $log) {}

    public function via(object $notifiable): array
    {
        return ['database'];
    }

    public function toArray(object $notifiable): array
    {
        $complaint = $this->log->delivery_status === EmailLog::DELIVERY_COMPLAINT;
        $user = $this->log->user;

        return [
            'type' => 'email',
            'title' => $complaint ? 'An email was marked as spam' : 'An email did not reach its person',
            'body' => sprintf(
                '"%s" to %s%s %s. Check the address.',
                $this->log->subject ?: $this->log->kind_label,
                $this->log->to_address,
                $user ? " ({$user->name})" : '',
                $complaint ? 'was marked as spam' : 'bounced'.($this->log->delivery_detail ? ': '.$this->log->delivery_detail : ''),
            ),
            'action_url' => $user && $user->isCustomer() ? "/admin/customers/{$user->id}" : '/admin/email-log',
        ];
    }
}
