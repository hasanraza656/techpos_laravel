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

    // Initial manual-payment instructions used only when seeding a fresh database. Real account
    // details stay in .env, never in source. Later edits belong in Platform settings.
    'payment_instructions' => [
        'bank' => ['bank' => env('PAYMENT_BANK_NAME'), 'account_number' => env('PAYMENT_BANK_ACCOUNT'), 'account_holder' => env('PAYMENT_BANK_HOLDER')],
        'jazzcash' => ['account_number' => env('PAYMENT_JAZZCASH_ACCOUNT')],
        'easypaisa' => ['account_number' => env('PAYMENT_EASYPAISA_ACCOUNT')],
    ],

];
