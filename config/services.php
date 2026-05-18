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

    'mailgun' => [
        'domain' => env('MAILGUN_DOMAIN'),
        'secret' => env('MAILGUN_SECRET'),
        'endpoint' => env('MAILGUN_ENDPOINT', 'api.mailgun.net'),
        'scheme' => 'https',
    ],

    'postmark' => [
        'token' => env('POSTMARK_TOKEN'),
    ],

    'ses' => [
        'key' => env('AWS_ACCESS_KEY_ID'),
        'secret' => env('AWS_SECRET_ACCESS_KEY'),
        'region' => env('AWS_DEFAULT_REGION', 'us-east-1'),
    ],

    'stripe' => [
        'key'            => env('STRIPE_KEY'),
        'secret'         => env('STRIPE_SECRET'),
        'webhook_secret' => env('STRIPE_WEBHOOK_SECRET'),
    ],

    'google' => [
        'client_id'     => env('GOOGLE_CLIENT_ID'),
        'client_secret' => env('GOOGLE_CLIENT_SECRET'),
        'redirect'      => env('GOOGLE_REDIRECT_URL'),
    ],

    // roke.pet — planes de suscripción (Stripe Price IDs)
    'rokepet' => [
        'frontend_url'        => env('ROKEPET_FRONTEND_URL', 'https://roke.pet'),
        'stripe_price_starter'=> env('ROKEPET_STRIPE_PRICE_STARTER'),
        'stripe_price_pro'    => env('ROKEPET_STRIPE_PRICE_PRO'),
        'trial_days'          => (int) env('ROKEPET_TRIAL_DAYS', 14),
    ],

    'pterodactyl' => [
        'url' => env('PTERODACTYL_URL', 'https://panel.rokeindustries.net'),
        'key' => env('PTERODACTYL_API_KEY'),
    ],

    'cloudflare' => [
        'token'   => env('CLOUDFLARE_API_TOKEN'),
        'zone_id' => env('CLOUDFLARE_ZONE_ID'),
    ],

];
