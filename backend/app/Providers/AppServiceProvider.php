<?php

namespace App\Providers;

use App\Services\PlaidClient;
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
    }
}
