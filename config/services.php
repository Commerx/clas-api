<?php

return [

    'google' => [
        // Only used to resolve a timezone name for a coordinate, cached
        // forever per location. Rotate the key that was hardcoded in the
        // Yii app before reusing it here.
        'timezone_key' => env('GOOGLE_TIMEZONE_KEY'),
    ],

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

    /*
     * Stripe. Phase 1 of the Moneris-to-Stripe move takes new customers
     * here while the Yii application keeps charging the existing book
     * through Moneris; docs/moneris-to-stripe-migration.md has the plan.
     *
     * The keys currently in .env are the marketplace *test* keys, copied
     * once. Nothing in this application reads them from the Yii config at
     * runtime.
     *
     * A secret key alone does not enable payments: it must also be a key
     * this deployment is willing to use, which in test mode means
     * `sk_test_`. `StripeGateway::isConfigured()` is what the endpoints
     * check, and it returns false for a live key unless the prefix below
     * says otherwise.
     */
    'stripe' => [
        'key' => env('STRIPE_KEY'),
        'secret' => env('STRIPE_SECRET'),
        'webhook_secret' => env('STRIPE_WEBHOOK_SECRET'),

        /*
         * Which key prefixes this deployment accepts. Test mode is the
         * default everywhere, so a live key has to be opted into by naming
         * it here — a deployment cannot start charging real cards because
         * somebody pasted the wrong key into .env.
         */
        'allowed_key_prefixes' => array_filter(explode(',', (string) env(
            'STRIPE_ALLOWED_KEY_PREFIXES',
            'sk_test_',
        ))),

        /* The currency every amount is charged in. */
        'currency' => env('STRIPE_CURRENCY', 'cad'),
    ],

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

];
