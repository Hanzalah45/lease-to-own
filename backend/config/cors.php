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
     *   FRONTEND_URL=http://localhost:3000,https://prostartleasing.com
     *
     * In production, frontend and backend run side by side on the same
     * server behind Nginx, so the browser only ever calls prostartleasing.com
     * and this allow-list doesn't come into play there — it matters for
     * local development and any future setup where they're split again.
     */
    'allowed_origins' => array_values(array_filter(array_map(
        'trim',
        explode(',', (string) env('FRONTEND_URL', 'http://localhost:3000')),
    ))),

    /*
     * Optional regex list (also comma-separated) for origins whose host is
     * not fixed — e.g. preview/staging deployments on a shared subdomain
     * pattern. Keep any pattern anchored and scoped to your own project
     * prefix, never a bare wildcard host.
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
