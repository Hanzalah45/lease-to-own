<?php

namespace App\Notifications\Concerns;

use Illuminate\Notifications\Messages\MailMessage;

/**
 * Every notification's toArray() already returns {type, title, body, action_url}
 * for the in-app feed. This reuses that same payload to build the email so the
 * two representations can't drift apart — override toMail() only when a
 * notification needs something extra (e.g. an attachment).
 */
trait BuildsMailFromArray
{
    public function toMail(object $notifiable): MailMessage
    {
        return $this->buildBaseMail($notifiable);
    }

    /** Exposed separately so a class that needs extras (e.g. an attachment) can override toMail() and still start from this. */
    protected function buildBaseMail(object $notifiable): MailMessage
    {
        $data = $this->toArray($notifiable);

        $mail = (new MailMessage)->subject($data['title']);

        // Every recipient here is a real customer/admin/super-admin User —
        // this trait is never used for an anonymous/routed notifiable — so
        // ->name is always safe, and a plain "Hello!" would otherwise be the
        // default on every single one of these emails.
        if (isset($notifiable->name)) {
            $mail->greeting("Hi {$notifiable->name},");
        }

        // The subject line isn't shown in the email body by most clients, and
        // several notifications treat "body" as a secondary/optional detail
        // (e.g. an admin's notes) with the real headline only in "title" —
        // restate it as a bold line so the email is never just "Tap to view
        // the full details." with no indication of what actually happened.
        $mail->line("**{$data['title']}**");

        if ($data['body'] && $data['body'] !== $data['title']) {
            $mail->line($data['body']);
        }

        // A null action_url means the recipient has no usable portal login
        // yet (e.g. a guest-originated customer before account setup) — a
        // "View in portal" button they can't get past is worse than no
        // button at all.
        if (! $data['action_url']) {
            return $mail;
        }

        $url = rtrim((string) config('app.frontend_url'), '/').$data['action_url'];

        return $mail->action('View in portal', $url);
    }
}
