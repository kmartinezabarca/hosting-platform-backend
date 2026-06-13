<?php

return [
    /*
    |--------------------------------------------------------------------------
    | API Request Logging
    |--------------------------------------------------------------------------
    |
    | Stores a sanitized technical trace for API requests. This is separate
    | from activity_logs/audit_logs: those describe business events, while
    | api_request_logs is for reproducing, debugging, and reporting requests.
    |
    */

    'enabled' => env('API_REQUEST_LOG_ENABLED', env('APP_ENV') !== 'testing'),

    'log_successful' => env('API_REQUEST_LOG_SUCCESSFUL', true),
    'log_request_body' => env('API_REQUEST_LOG_REQUEST_BODY', true),
    'log_response_body' => env('API_REQUEST_LOG_RESPONSE_BODY', true),
    'log_error_trace' => env('API_REQUEST_LOG_ERROR_TRACE', false),

    'max_body_bytes' => (int) env('API_REQUEST_LOG_MAX_BODY_BYTES', 32768),
    'max_header_bytes' => (int) env('API_REQUEST_LOG_MAX_HEADER_BYTES', 8192),

    'sample_rate' => (float) env('API_REQUEST_LOG_SAMPLE_RATE', 1.0),

    'retention_days' => (int) env('API_REQUEST_LOG_RETENTION_DAYS', 90),

    'except' => array_filter(array_map(
        'trim',
        explode(',', env('API_REQUEST_LOG_EXCEPT', 'sanctum/csrf-cookie,api/request-logs*,api/admin/api-request-logs*'))
    )),

    'sensitive_keys' => [
        'authorization',
        'cookie',
        'set-cookie',
        'x-csrf-token',
        'x-xsrf-token',
        'password',
        'password_confirmation',
        'current_password',
        'token',
        'access_token',
        'refresh_token',
        'secret',
        'webhook_secret',
        'client_secret',
        'api_key',
        'private_key',
        'private-key',
        'app_key',
        'stripe_secret',
        'card_number',
        'cvc',
        'cvv',
    ],
];
