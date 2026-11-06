<?php

/*
 * Reference data and prices the app downloads on startup.
 *
 * These were literals inside the old SecurityController::actionVersionCheck,
 * which meant a price change was a code deploy. They are configuration here,
 * but they are still the same values — the apps display them, so changing one
 * changes what a user is quoted.
 */

return [

    /*
     * Where the Yii application still lives.
     *
     * Email confirmation and password reset are web pages served by that
     * application, and the links in the mail this API sends have to point at
     * it. Both applications share one `token` table, so a link created here
     * is consumed there.
     */
    'legacy_web_url' => env('LEGACY_WEB_URL', 'https://canadianlandaccess.com'),

    /* Who hears about a reported issue, beyond the admin address. */
    'issue_notify_emails' => array_filter(explode(',', (string) env('ISSUE_NOTIFY_EMAILS', ''))),

    'admin_email' => env('ADMIN_EMAIL', 'info@canadianlandaccess.com'),

    /* How long a confirmation or recovery link stays usable, in seconds. */
    'confirm_within' => 86400,
    'recover_within' => 21600,

    /* An unconfirmed account keeps working for this long after signing up. */
    'guest_days' => 7,

    /*
     * What things cost, and what a subscription of each type is.
     *
     * This is the only source of truth for this application. Nothing here
     * reads the Yii application's `config/params.php` or checks a price
     * against it at runtime: the two applications are separate deployments
     * that happen to share a database, and a runtime dependency on the old
     * one would outlive it. The values below were copied across once, from
     * the production environment file, and from here on they are changed
     * here.
     *
     * Noted because it matters when comparing the two: the Yii base
     * `config/params.php` carries a stale map with only types 1-3 and type 3
     * at 69.99, which every environment file then overrides with types 1-5
     * and type 3 at 9.99. Production uses the override. The figures here
     * follow the override, not the base file.
     */
    'subscriptions' => [
        /*
         * `user_subscription.subscription_type`. The id is what the column
         * holds; both applications write it, so the numbers are fixed.
         *
         * `renews` says whether the old Yii renewal cron would charge it: it
         * selects types 3, 4 and 5 only, so 1 and 2 are legacy and no longer
         * renew. Keep them listed anyway — 892 rows carry them and their
         * entitlement still has to be read.
         *
         * `amount` is per charge, not per month: type 2 is six months at
         * 8.99 a month, which the old code expressed as a multiplication at
         * the point of sale.
         */
        'types' => [
            1 => ['name' => 'Monthly', 'period' => 'P1M', 'amount' => '29.99', 'renews' => false, 'sold' => false],
            2 => ['name' => '6 Months', 'period' => 'P6M', 'amount' => '53.94', 'renews' => false, 'sold' => false],
            3 => ['name' => 'Annual', 'period' => 'P1Y', 'amount' => '9.99', 'renews' => true, 'sold' => true],
            4 => ['name' => 'Annual - Guide', 'period' => 'P1Y', 'amount' => '9.99', 'renews' => true, 'sold' => true],
            5 => ['name' => 'Annual', 'period' => 'P1Y', 'amount' => '9.99', 'renews' => true, 'sold' => true],
        ],

        /*
         * What a new property-access subscription includes for free.
         *
         * Both the paid and the free path in the old API created a year of
         * WMU and fishing-lands access alongside the main subscription, at
         * 0.00 with txn_number 'FREE ADD-ON'. That is why those two tables
         * hold hundreds of rows and almost no approved payments: they are
         * mostly add-ons, not a product that never sold.
         */
        'free_addons' => [
            'wmu_subscription' => ['period' => 'P1Y', 'order_prefix' => 'CLAS-WMU-ORDER'],
            'fishing_lands_subscription' => ['period' => 'P1Y', 'order_prefix' => 'CLAS-FISHING-ORDER'],
        ],

        /*
         * Renewals, for the subscriptions this API owns.
         *
         * The old cron charged on exactly two days: the expiration date
         * itself, and seven days later. Any other day it skipped the row
         * entirely. So a cron that did not run on one of those two dates —
         * a deploy, a server restart, a failed run — never charged that
         * subscription at all, and the expiry sweep then expired it a week
         * later. Nothing recorded that a renewal had been missed rather than
         * declined.
         *
         * Here the grace period is the same seven days and the attempt cap is
         * the same two, but an attempt happens on any day inside the window
         * where one has not already been made. A missed run costs a day, not
         * the renewal.
         */
        'renewal' => [
            'grace_days' => (int) env('RENEWAL_GRACE_DAYS', 7),
            'max_attempts' => (int) env('RENEWAL_MAX_ATTEMPTS', 2),
        ],

        /*
         * The three add-on products, each sold on its own.
         *
         * A year of each comes free with a property-access subscription (see
         * free_addons above); these endpoints are for buying one separately,
         * or again after the free year has run out.
         *
         * All three were near-identical controllers in the old API — 346 to
         * 355 lines each, differing in the table, the price key and the
         * error message. One definition here, one code path.
         */
        'products' => [
            'wmu' => [
                'name' => 'WMU Layer',
                'table' => 'wmu_subscription',
                'transactions' => 'wmu_subscription_transactions',
                'amount' => '4.99',
                'period' => 'P1Y',
                'order_prefix' => 'CLAS-WMU-ORDER',
            ],
            'dark_mode' => [
                'name' => 'Dark Mode / Privacy',
                'table' => 'darkmode_subscription',
                'transactions' => 'darkmode_subscription_transactions',
                'amount' => '49.99',
                'period' => 'P1Y',
                'order_prefix' => 'CLAS-DARKMODE-ORDER',
            ],
            'fishing_lands' => [
                'name' => 'Fishing Lands Regulations',
                'table' => 'fishing_lands_subscription',
                'transactions' => 'fishing_lands_subscription_transactions',
                'amount' => '4.99',
                'period' => 'P1Y',
                'order_prefix' => 'CLAS-FISHING-ORDER',
            ],
        ],

        /* The type a new property-access subscription is created as. */
        'default_type' => 5,
    ],

    /*
     * Promotional codes.
     *
     * Two defects in the old implementation, both of which the data shows
     * were exercised, so both are fixed here:
     *
     * **`promo_codes.type` was ignored.** Any code present in the table gave
     * 100% off. The column has three values — onetime, annual50, sales100 —
     * and 300 `annual50` codes were therefore granting a free year instead of
     * half off. `rates` below is what each type means.
     *
     * **`used` was never checked.** The old code incremented it and never
     * read it, so a code could be redeemed without limit. One code named
     * `onetime` has been used 28 times, another 7, two more twice. `single_use`
     * is the list of types that may be redeemed once.
     *
     * Honouring the type is a behaviour change for codes already in
     * circulation: 298 unused `annual50` codes become 50% off rather than
     * free. That is what the column says they are, and the old behaviour was
     * a mispricing, but it is a change customers could notice.
     */
    'promocodes' => [
        /*
         * Codes that are not rows in `promo_codes` at all — the old
         * controller special-cased these three literals. Kept, because they
         * have been handed out, but as configuration rather than an `if`.
         */
        'fixed' => [
            'ABP20' => 20,
            'LO20' => 20,
            'WSG20' => 20,
        ],

        /* Percentage off, per `promo_codes.type`. */
        'rates' => [
            'onetime' => 100,
            'annual50' => 50,
            'sales100' => 100,
        ],

        /* Types that may only be redeemed once. */
        'single_use' => ['onetime'],
    ],

    'prices' => [
        /* What the app shows on the subscription screen. */
        'property_access' => [
            [
                'id' => 5,
                'name' => '12 MONTH ACCESS',
                'label' => '$9.99/YEAR',
                'description' => 'You can enter multiple lands multiple times for 12 months.',
                'amount' => '9.99',
            ],
        ],
        'wmu' => ['annual' => '4.99', 'AB' => '4.99', 'SK' => '4.99'],
        'dark_mode' => ['annual' => '49.99'],
        'fishing_lands' => ['annual' => '4.99'],
    ],

    'signage_options' => [
        ['id' => 1, 'name' => 'Red Access Sign', 'image' => 'red-sign.png', 'price' => 49.99],
        ['id' => 2, 'name' => 'Black Boundary Sign', 'image' => 'signage-black.png', 'price' => 49.99],
        ['id' => 3, 'name' => 'Decals', 'image' => 'decals-sign.png', 'price' => 13.99],
        ['id' => 4, 'name' => 'Large Sign', 'image' => 'large-sign.png', 'price' => 399.99],
    ],

    /*
     * Reasons a booking can be made for.
     *
     * `request_reasons` is what a private listing offers; an open property
     * offers a different set, because its allowances are counted per activity
     * (see App\Services\BookingSubmission::ACTIVITY_LIMITS, which must stay in
     * step with `open_property_request_reasons`).
     */
    'request_reasons' => [
        'Hunting', 'Fishing', 'Research/Education', 'Institutional',
        'Photography', 'Sales', 'Consumer', 'Farming',
    ],

    'open_property_request_reasons' => [
        'Foot Access', 'Hunting', 'Fishing', 'OHV', 'Horse', 'SnowMobile',
    ],

    'open_property_activity_types' => [
        'ATV', 'SnowMobile', 'Motorised Vehicles',
    ],

    /*
     * Where listing photos live.
     *
     * The app is given bare file names and builds its own URLs, so a photo
     * uploaded through this API has to land where the application serving
     * those URLs can find it. Today that is the old Yii app's document root,
     * which this API writes into directly — the two run on the same host.
     *
     * This needs settling before the new API moves to its own subdomain: a
     * shared volume, an object store with a new base URL the app is told
     * about, or serving photos from this application. Until then the path is
     * configuration rather than a constant, so moving it is one env change.
     */
    'uploads' => [
        'photos_path' => env('PHOTOS_PATH', 'D:/laragon/www/clas/web/uploads/photos'),

        /* What a photo may be. The old endpoint checked neither. */
        'photo_max_bytes' => (int) env('PHOTO_MAX_BYTES', 10 * 1024 * 1024),
        'photo_types' => ['jpg', 'jpeg', 'png', 'gif', 'webp'],

        /* The two derived sizes the app expects beside each original. */
        'photo_sizes' => [
            'list_' => [400, 200],
            'detail_' => [800, 400],
        ],
    ],

    /*
     * Coordinates a map centred itself on, which arrive looking like a
     * position but are not one. The first pair is the old web app's default
     * map centre, hardcoded in ten of its views and also the coordinate of
     * the Waldron listing; the second is the marketplace map's. A profile
     * holding one of these is treated as having no position at all, and a
     * client sending one has it dropped rather than stored. The Position
     * class in App\Support\V1 explains why, and what the data holds today.
     */
    'position' => [
        'placeholders' => [
            [49.90261265, -114.12588656],
            [54.4217013, -119.4839251],
        ],
    ],

    /* What the app offers at check-out, grouped as the picker shows them. */
    'checkout_categories' => [
        'Big Game' => [
            'Elk (A)', 'Elk (AL)', 'Moose (A)', 'Moose (AL)', 'Mule Deer (A)',
            'Mule Deer (AL)', 'Whitetail (A)', 'Whitetail (AL)', 'Sheep',
            'Mountain Goat',
        ],
        'Migratory/Non-Migratory' => [
            'Geese', 'Ducks', 'Partridge', 'Pheasant', 'Grouse', 'Crane',
        ],
        'Predation' => [
            'Bear', 'Wolf', 'Cougar', 'Coyote', 'Bobcat', 'Red fox',
        ],
        'Fur-Bearing/Trapping' => [
            'Badger', 'Porcupine', 'Squirrel', 'Rabbit', 'Raccoon', 'Skunk',
            'Woodchuck', 'Muskrat', 'Beaver', 'Martin', 'Coyote', 'Wolf',
            'Red fox', 'Lynx', 'Mole', 'Bobcat',
        ],
        'Wildlife Management' => [
            'Elk (either sex)', 'Mule deer (either sex)',
            'Whitetail (either sex)', 'Geese', 'Bear', 'Coyote', 'Wolf',
            'Gopher', 'Rabbit', 'Beaver', 'Wild pig',
        ],
    ],
];
