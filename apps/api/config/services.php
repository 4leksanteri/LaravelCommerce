<?php

declare(strict_types=1);

return [

    /*
    |--------------------------------------------------------------------------
    | Third Party Services
    |--------------------------------------------------------------------------
    |
    | This file is for storing the credentials for third party services such
    | as Resend, Postmark, AWS, and more. This file provides the de facto
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

    /*
    |--------------------------------------------------------------------------
    | Stripe Connect
    |--------------------------------------------------------------------------
    |
    | Test keys only. There is no live key in this repository and there will
    | not be one - the whole of this exists in Stripe test mode (ADR 0015).
    |
    | `webhook_secret` is not the API key. Stripe signs each webhook with a
    | separate signing secret, and verifying that signature is the only thing
    | standing between the endpoint and anybody who knows its URL - it is the
    | one route in this application that has to be publicly reachable.
    |
    | `country` is the platform's own, and it decides which countries a
    | connected account may be created in and what verification each needs.
    |
    */

    'stripe' => [
        'secret' => env('STRIPE_SECRET'),
        'webhook_secret' => env('STRIPE_WEBHOOK_SECRET'),
        'country' => env('STRIPE_PLATFORM_COUNTRY', 'FI'),
    ],

];
