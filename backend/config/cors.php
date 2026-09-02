<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Cross-Origin Resource Sharing (CORS) Configuration
    |--------------------------------------------------------------------------
    |
    | Here you may configure your settings for cross-origin resource sharing
    | or "CORS". This determines what cross-origin operations may execute
    | in web browsers. You are free to adjust these settings as needed.
    |
    | To learn more: https://developer.mozilla.org/en-US/docs/Web/HTTP/CORS
    |
    */

    'paths' => ['api/*', 'sanctum/csrf-cookie'],

    'allowed_methods' => ['*'],

    /*
     * Comma-separated, so one deployment can serve several front ends — e.g.
     * local development plus the live site:
     *
     *   FRONTEND_URL=http://localhost:3000,https://frontend-dusky-theta-28.vercel.app
     *
     * Use Vercel's STABLE alias here, not the per-deployment URL: every
     * `vercel --prod` mints a fresh frontend-<hash>-....vercel.app, so pinning
     * one of those breaks CORS again on the very next deploy.
     */
    'allowed_origins' => array_values(array_filter(array_map(
        'trim',
        explode(',', (string) env('FRONTEND_URL', 'http://localhost:3000')),
    ))),

    /*
     * Optional regex list (also comma-separated) for origins whose host is not
     * fixed — Vercel preview deployments being the case this exists for. Keep
     * any pattern anchored and scoped to your own project prefix; a bare
     * `.*\.vercel\.app` would let anybody's Vercel site call this API.
     */
    'allowed_origins_patterns' => array_values(array_filter(array_map(
        'trim',
        explode(',', (string) env('FRONTEND_URL_PATTERNS', '')),
    ))),

    'allowed_headers' => ['*'],

    'exposed_headers' => [],

    'max_age' => 0,

    'supports_credentials' => false,

];
