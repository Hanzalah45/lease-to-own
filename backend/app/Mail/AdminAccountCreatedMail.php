<?php

namespace App\Mail;

use App\Models\User;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/**
 * Sent once, at creation time, by the super admin flow. Carries the
 * plaintext password since the admin has no other way to receive it —
 * it is never persisted or logged anywhere else.
 */
class AdminAccountCreatedMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public User $admin,
        public string $plainPassword,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: 'Your Outdoor Fix admin account is ready',
        );
    }

    public function content(): Content
    {
        return new Content(
            view: 'emails.admin-account-created',
            with: [
                'name' => $this->admin->name,
                'email' => $this->admin->email,
                'password' => $this->plainPassword,
                'loginUrl' => rtrim(config('app.frontend_url'), '/').'/login',
            ],
        );
    }
}
