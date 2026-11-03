<?php

/**
 * The request matrix used by tools/contract-diff.php and by
 * tests/Feature/Api/V1/ContractParityTest.php.
 *
 * Add a case per endpoint as it is rebuilt.
 *
 * Credentials are resolved from the database at runtime rather than written
 * here, so no real token ever lands in the repository:
 *
 *   __UID__          the local fixture account (see tests/Fixtures/v1/README.md)
 *   __TOKEN__        its auth_key
 *   __NOTIF_UID__    some account that actually has notifications, so the
 *   __NOTIF_TOKEN__  list endpoint is exercised with rows rather than []
 *
 * A case whose placeholders cannot be resolved is dropped from the matrix, so
 * a database without the fixture account still runs the rest.
 */
$fixtureLogin = 'contract_fixture';
$fixtureEmail = 'contract-fixture@example.invalid';
$fixturePass = 'ContractTest123!';

$resolve = static function (): array {
    $host = getenv('CONTRACT_DB_HOST') ?: '127.0.0.1';
    $name = getenv('CONTRACT_DB') ?: 'clas';
    $user = getenv('CONTRACT_DB_USER') ?: 'root';
    $pass = getenv('CONTRACT_DB_PASSWORD');
    $pass = $pass === false ? 'root' : $pass;

    $values = [];

    try {
        $pdo = new PDO("mysql:host={$host};dbname={$name}", $user, $pass, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        ]);

        $row = $pdo->query(
            "SELECT id, auth_key FROM user WHERE username = 'contract_fixture' LIMIT 1"
        )->fetch(PDO::FETCH_ASSOC);

        if ($row) {
            $values['__UID__'] = (string) $row['id'];
            $values['__TOKEN__'] = (string) $row['auth_key'];
        }

        $row = $pdo->query(
            "SELECT u.id, u.auth_key FROM user u
             JOIN notifications_users_mapping m ON m.user_id = u.id
             WHERE u.auth_key <> ''
             GROUP BY u.id, u.auth_key LIMIT 1"
        )->fetch(PDO::FETCH_ASSOC);

        if ($row) {
            $values['__NOTIF_UID__'] = (string) $row['id'];
            $values['__NOTIF_TOKEN__'] = (string) $row['auth_key'];
        }
    } catch (Throwable) {
        // No database reachable: every placeholder case is dropped below.
    }

    return $values;
};

$cases = [
    'login / valid by username' => [
        'method' => 'POST',
        'path' => 'v1/security/login',
        'body' => ['login' => $fixtureLogin, 'password' => $fixturePass, 'os' => 'android'],
    ],
    'login / valid by email' => [
        'method' => 'POST',
        'path' => 'v1/security/login',
        'body' => ['login' => $fixtureEmail, 'password' => $fixturePass, 'os' => 'android'],
    ],
    'login / wrong password' => [
        'method' => 'POST',
        'path' => 'v1/security/login',
        'body' => ['login' => $fixtureLogin, 'password' => 'definitely-wrong', 'os' => 'android'],
    ],
    'login / unknown user' => [
        'method' => 'POST',
        'path' => 'v1/security/login',
        'body' => ['login' => 'nobody-here-at-all', 'password' => 'x', 'os' => 'ios'],
    ],
    'login / missing os' => [
        'method' => 'POST',
        'path' => 'v1/security/login',
        'body' => ['login' => $fixtureLogin, 'password' => $fixturePass],
    ],
    'login / missing password' => [
        'method' => 'POST',
        'path' => 'v1/security/login',
        'body' => ['login' => $fixtureLogin, 'os' => 'android'],
    ],
    'login / missing login' => [
        'method' => 'POST',
        'path' => 'v1/security/login',
        'body' => ['password' => $fixturePass, 'os' => 'android'],
    ],
    'login / GET instead of POST' => [
        'method' => 'GET',
        'path' => 'v1/security/login',
    ],

    // Token middleware. The old API performs this check inside every action, so these
    // pin the error strings, their order, and the fact that an auth failure is
    // HTTP 200 with an error body rather than a 401.
    'auth / no credentials' => [
        'method' => 'GET',
        'path' => 'v1/notifications/list',
    ],
    'auth / token only' => [
        'method' => 'GET',
        'path' => 'v1/notifications/list?token=__TOKEN__',
    ],
    'auth / uid only' => [
        'method' => 'GET',
        'path' => 'v1/notifications/list?uid=__UID__',
    ],
    'auth / wrong token' => [
        'method' => 'GET',
        'path' => 'v1/notifications/list?token=deadbeefdeadbeefdeadbeefdeadbeef&uid=__UID__',
    ],
    'auth / mismatched uid' => [
        'method' => 'GET',
        'path' => 'v1/notifications/list?token=__TOKEN__&uid=999999',
    ],
    'auth / non-numeric uid' => [
        'method' => 'GET',
        'path' => 'v1/notifications/list?token=__TOKEN__&uid=abc',
    ],

    'notifications / list (empty)' => [
        'method' => 'GET',
        'path' => 'v1/notifications/list?token=__TOKEN__&uid=__UID__',
    ],
    'notifications / list (with rows)' => [
        'method' => 'GET',
        'path' => 'v1/notifications/list?token=__NOTIF_TOKEN__&uid=__NOTIF_UID__&limit=3',
    ],
    'notifications / categories' => [
        'method' => 'GET',
        'path' => 'v1/notifications/categories?token=__TOKEN__&uid=__UID__',
    ],

    /*
     * Listings.
     *
     * geofence-listings used to return a 500 from the old API for any page of 13 or
     * more rows: land_id 6418 holds the string 'null' in its points column,
     * json_decode returns NULL without error, and foreach(null) raised a
     * warning that Yii escalated. The Yii app now guards that loop, so the
     * two implementations can be compared across the whole set — the
     * 'past the previously-crashing row' case below is what proves it.
     */
    'listings / all' => [
        'method' => 'GET',
        'path' => 'v1/listings/all?token=__TOKEN__&uid=__UID__&limit=5',
    ],
    'listings / all with position' => [
        'method' => 'GET',
        'path' => 'v1/listings/all?token=__TOKEN__&uid=__UID__&limit=3&user_lat=49.90261265&user_lng=-114.12588656',
    ],
    'listings / all with start offset' => [
        'method' => 'GET',
        'path' => 'v1/listings/all?token=__TOKEN__&uid=__UID__&limit=3&start=5',
    ],
    'listings / geofence (10 rows, below the bad row)' => [
        'method' => 'GET',
        'path' => 'v1/listings/geofence-listings?token=__TOKEN__&uid=__UID__&limit=10',
    ],
    'listings / geofence past the previously-crashing row' => [
        'method' => 'GET',
        'path' => 'v1/listings/geofence-listings?token=__TOKEN__&uid=__UID__&limit=20',
    ],
    'listings / geofence whole set' => [
        'method' => 'GET',
        'path' => 'v1/listings/geofence-listings?token=__TOKEN__&uid=__UID__',
    ],
    'listings / geofence with position' => [
        'method' => 'GET',
        'path' => 'v1/listings/geofence-listings?token=__TOKEN__&uid=__UID__&limit=4&user_lat=49.90261265&user_lng=-114.12588656',
    ],
    'listings / first-nations' => [
        'method' => 'GET',
        'path' => 'v1/listings/first-nations-listings?token=__TOKEN__&uid=__UID__',
    ],
];

$values = $resolve();

$resolved = [];

foreach ($cases as $name => $case) {
    $path = $case['path'];

    if (preg_match_all('/__[A-Z_]+__/', $path, $found)) {
        $missing = array_diff($found[0], array_keys($values));

        if ($missing) {
            continue; // cannot build this request; skip rather than guess
        }

        $case['path'] = str_replace(array_keys($values), array_values($values), $path);
    }

    $resolved[$name] = $case;
}

return $resolved;
