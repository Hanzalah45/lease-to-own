<?php

namespace App\Providers;

use App\Services\EmailLogger;
use App\Services\PlaidClient;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Mail\Events\MessageSending;
use Illuminate\Mail\Events\MessageSent;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->singleton(PlaidClient::class, function () {
            return new PlaidClient(
                clientId: config('services.plaid.client_id'),
                secret: config('services.plaid.secret'),
                env: config('services.plaid.env'),
            );
        });

        // One instance per request, so the email log knows which messages are still in flight.
        $this->app->singleton(EmailLogger::class);
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        // Older MySQL/MariaDB (no innodb_large_prefix) caps index keys at
        // 767-1000 bytes. utf8mb4 uses 4 bytes/char, so varchar(255) unique
        // columns overflow that on hosts like this. 191 chars keeps every
        // indexed string column under the limit regardless of server config.
        Schema::defaultStringLength(191);

        // The email log (client, Joel, 2026-10-13): a record of every email sent and what the
        // email service answered. These listeners return nothing on purpose; returning false
        // from a "sending" listener would cancel the email.
        Event::listen(MessageSending::class, function (MessageSending $event) {
            app(EmailLogger::class)->sending($event);
        });
        Event::listen(MessageSent::class, function (MessageSent $event) {
            app(EmailLogger::class)->sent($event);
        });

        // The API is a separate origin from the frontend, so the reset link
        // in the email must point at the Next.js app, not this Laravel app.
        ResetPassword::createUrlUsing(function ($notifiable, string $token) {
            $email = urlencode($notifiable->getEmailForPasswordReset());

            return config('app.frontend_url') . "/reset-password?token={$token}&email={$email}";
        });
    }
}
