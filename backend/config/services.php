<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Third Party Services
    |--------------------------------------------------------------------------
    |
    | This file is for storing the credentials for third party services such
    | as Mailgun, Postmark, AWS and more. This file provides the de facto
    | location for this type of information, allowing packages to have
    | a conventional file to locate the various service credentials.
    |
    */

    'postmark' => [
        'key' => env('POSTMARK_API_KEY'),
    ],

    'resend' => [
        'key' => env('RESEND_API_KEY'),
    ],

    'ses' => [
        'key' => env('AWS_ACCESS_KEY_ID'),
        'secret' => env('AWS_SECRET_ACCESS_KEY'),
        'region' => env('AWS_DEFAULT_REGION', 'us-east-1'),
    ],

    'slack' => [
        'notifications' => [
            'bot_user_oauth_token' => env('SLACK_BOT_USER_OAUTH_TOKEN'),
            'channel' => env('SLACK_BOT_USER_DEFAULT_CHANNEL'),
        ],
    ],

    'plaid' => [
        'client_id' => env('PLAID_CLIENT_ID'),
        'secret' => env('PLAID_SECRET'),
        'env' => env('PLAID_ENV', 'sandbox'),
    ],

    // Nothing here is ever committed with a real value — see .env.example.
    // Build and test everything against STRIPE_MODE=test (Stripe's test-mode
    // keys, e.g. pk_test_.../sk_test_...) until the full flow is verified;
    // only then does production get switched to live keys.
    'stripe' => [
        'key' => env('STRIPE_KEY'),
        'secret' => env('STRIPE_SECRET'),
        'webhook_secret' => env('STRIPE_WEBHOOK_SECRET'),
    ],

    // OAuth client registered under the developer's own Intuit account — this
    // is what Joel authorizes against once the connection UI exists, not a
    // credential of his own.
    'quickbooks' => [
        'client_id' => env('QUICKBOOKS_CLIENT_ID'),
        'client_secret' => env('QUICKBOOKS_CLIENT_SECRET'),
        'env' => env('QUICKBOOKS_ENV', 'sandbox'),
        'redirect_uri' => env('QUICKBOOKS_REDIRECT_URI'),
        // Intuit's endpoints. Only a local stand-in for Intuit (tests/fake-intuit) ever overrides these.
        'authorize_url' => env('QUICKBOOKS_AUTHORIZE_URL', 'https://appcenter.intuit.com/connect/oauth2'),
        'token_url' => env('QUICKBOOKS_TOKEN_URL', 'https://oauth.platform.intuit.com/oauth2/v1/tokens/bearer'),
        'revoke_url' => env('QUICKBOOKS_REVOKE_URL', 'https://developer.api.intuit.com/v2/oauth2/tokens/revoke'),
        'api_base' => env('QUICKBOOKS_API_BASE') ?: (env('QUICKBOOKS_ENV', 'sandbox') === 'production'
            ? 'https://quickbooks.api.intuit.com'
            : 'https://sandbox-quickbooks.api.intuit.com'),
    ],

];
