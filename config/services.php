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

    // roke.pet — planes de suscripción, Google OAuth y notificaciones push
    'rokepet' => [
        'frontend_url'          => env('ROKEPET_FRONTEND_URL', 'https://roke.pet'),
        // Stripe de roke.pet. Hoy comparte la cuenta de ROKE Industries (fallback al
        // STRIPE_SECRET/STRIPE_WEBHOOK_SECRET compartidos). Cuando roke.pet sea otra
        // entidad con su propia cuenta Stripe, basta con llenar estas dos variables
        // en el .env — sin tocar código. El webhook_secret SÍ debe ser propio del
        // endpoint /api/rp/stripe/webhook (cada endpoint de Stripe tiene su whsec).
        'stripe_secret'         => env('ROKEPET_STRIPE_SECRET', env('STRIPE_SECRET')),
        'stripe_webhook_secret' => env('ROKEPET_STRIPE_WEBHOOK_SECRET', env('STRIPE_WEBHOOK_SECRET')),
        'stripe_price_starter'  => env('ROKEPET_STRIPE_PRICE_STARTER'),
        'stripe_price_pro'      => env('ROKEPET_STRIPE_PRICE_PRO'),
        'trial_days'            => (int) env('ROKEPET_TRIAL_DAYS', 14),
        'google_client_id'      => env('ROKEPET_GOOGLE_CLIENT_ID'),
        'google_client_secret'  => env('ROKEPET_GOOGLE_CLIENT_SECRET'),
        'google_redirect'       => env('ROKEPET_GOOGLE_REDIRECT_URL'),
        'vapid_public_key'      => env('ROKEPET_VAPID_PUBLIC_KEY'),
        'vapid_private_key'     => env('ROKEPET_VAPID_PRIVATE_KEY'),
        'vapid_subject'         => env('ROKEPET_VAPID_SUBJECT', 'mailto:hola@roke.pet'),
        // FCM (Firebase Cloud Messaging) — for Flutter mobile push notifications
        // 1. Firebase Console → Project Settings → Service Accounts → Generate new private key
        // 2. Save the JSON file as storage/firebase-credentials.json
        // 3. Add to .env:
        //      FIREBASE_CREDENTIALS=firebase-credentials.json
        //      FCM_PROJECT_ID=hosting-plataform
        'firebase_credentials'  => env('FIREBASE_CREDENTIALS'),
        'fcm_project_id'        => env('FCM_PROJECT_ID', 'hosting-plataform'),
    ],

    'pterodactyl' => [
        'url' => env('PTERODACTYL_URL', 'https://panel.rokeindustries.net'),
        'key' => env('PTERODACTYL_API_KEY'),
    ],

    'cloudflare' => [
        'token'   => env('CLOUDFLARE_API_TOKEN'),
        'zone_id' => env('CLOUDFLARE_ZONE_ID'),
        'zone_name' => env('CLOUDFLARE_ZONE_NAME', 'rokeindustries.com'),
        'minecraft_srv_target' => env('CLOUDFLARE_MINECRAFT_SRV_TARGET', 'mc.rokeindustries.com'),
    ],

];
