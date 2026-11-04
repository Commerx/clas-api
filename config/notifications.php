<?php

return [

    /*
     * Addresses blind-copied on check-in and check-out notices.
     *
     * The old application hardcoded two named staff addresses in the
     * controller. They are configuration, not code — and worth confirming
     * anyone still wants them before this goes live. Empty disables the copy.
     */
    'movement_bcc' => array_filter(
        array_map('trim', explode(',', (string) env('NOTIFY_MOVEMENT_BCC', '')))
    ),

    /*
     * Who booking notices come from. Matches the old application's
     * adminEmail so recipients see the sender they already recognise.
     */
    'from' => [
        'address' => env('NOTIFY_FROM_ADDRESS', 'info@canadianlandaccess.com'),
        'name' => env('NOTIFY_FROM_NAME', 'Canadian Land Access'),
    ],

    /*
     * How far a community alert or hunting alert carries, in kilometres.
     *
     * The old controller had 25 written into four separate queries.
     */
    'radius_km' => (float) env('NOTIFY_RADIUS_KM', 25),

    /*
     * Push delivery.
     *
     * The old application posted to https://fcm.googleapis.com/fcm/send with
     * a server key hardcoded in the controller. That is FCM's legacy HTTP
     * API, which Google shut down in July 2024, so those calls have been
     * failing since — a commented-out line in the same method shows a move to
     * the v1 endpoint was started and never finished. Nothing in that code
     * looked at the response, so the failures were silent.
     *
     * This speaks the v1 API, which authenticates with a service account
     * rather than a static key. Until credentials are configured the driver
     * stays `log`: the fan-out still records every recipient and every
     * intended push is written to the log, so the behaviour is observable
     * without a live Firebase project.
     *
     * Drivers: log (default), fcm, null.
     */
    'push' => [
        'driver' => env('PUSH_DRIVER', 'log'),

        'fcm' => [
            'project_id' => env('FCM_PROJECT_ID'),
            // Path to the service account JSON. Keep it outside the webroot.
            'credentials' => env('FCM_CREDENTIALS'),
        ],
    ],

    /*
     * PEASI, the provincial emergency alerting service.
     *
     * The old code posted CAP 1.2 alerts to a hardcoded
     * mobile-teams-dev.peasi.ca URL with a bearer token in the source, from
     * production, and ignored the result. It was also reached only through
     * notifications/submit and not through the dedicated hunting-alert
     * endpoint, so whether an alert was forwarded depended on which endpoint
     * the app happened to call.
     *
     * Disabled unless a URL and token are configured, and the endpoint is now
     * a setting rather than a dev host baked into a controller. Confirm with
     * PEASI which environment and credentials to use before enabling.
     */
    'peasi' => [
        'enabled' => (bool) env('PEASI_ENABLED', false),
        'url' => env('PEASI_URL'),
        'token' => env('PEASI_TOKEN'),
        'timeout' => (int) env('PEASI_TIMEOUT', 5),
    ],

];
