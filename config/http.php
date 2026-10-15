<?php

return [
    /*
    |--------------------------------------------------------------------------
    | API Rate Limits
    |--------------------------------------------------------------------------
    |
    | Defines the rate limit for the number of requests per minute that can be
    | executed against both the client and internal (application) APIs over the
    | defined period (by default, 1 minute).
    |
    */
    'rate_limit' => [
        'client_period' => 1,
        'client' => env('APP_API_CLIENT_RATELIMIT', 256),

        'application_period' => 1,
        'application' => env('APP_API_APPLICATION_RATELIMIT', 256),

        // Password, email, 2FA, API key and SSH key changes, per user and action.
        'account_sensitive_period' => 60,
        'account_sensitive' => env('APP_API_ACCOUNT_SENSITIVE_RATELIMIT', 10),
    ],

    /*
    |--------------------------------------------------------------------------
    | Content Security Policy
    |--------------------------------------------------------------------------
    |
    | "off" sends no policy, "report" sends it as Content-Security-Policy-Report-Only
    | so that violations are reported to report_uri without anything being blocked,
    | and "enforce" blocks them. Sources can be appended to the directives below as a
    | space separated list, e.g. APP_CSP_EXTRA_CONNECT_SRC="ws://node.example.com:8080".
    |
    */
    'csp' => [
        'mode' => env('APP_CSP_MODE', 'off'),
        'report_uri' => '/csp-report',
        'extra' => [
            'script-src' => env('APP_CSP_EXTRA_SCRIPT_SRC'),
            'style-src' => env('APP_CSP_EXTRA_STYLE_SRC'),
            'img-src' => env('APP_CSP_EXTRA_IMG_SRC'),
            'connect-src' => env('APP_CSP_EXTRA_CONNECT_SRC'),
            'font-src' => env('APP_CSP_EXTRA_FONT_SRC'),
            'frame-src' => env('APP_CSP_EXTRA_FRAME_SRC'),
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Strict Transport Security
    |--------------------------------------------------------------------------
    |
    | When enabled, responses to HTTPS requests tell browsers to only use HTTPS for
    | this host for a year. Only enable this once the panel is permanently served
    | over HTTPS; browsers will refuse plain HTTP until the year is up.
    |
    */
    'hsts' => env('APP_HSTS', false),
];
