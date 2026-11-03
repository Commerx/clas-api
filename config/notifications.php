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

];
