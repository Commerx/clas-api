<?php

/**
 * The request matrix used by tools/contract-diff.php and the contract tests.
 *
 * Add a case per endpoint as it is rebuilt. Credentials here refer to a local
 * fixture account, never a real one — see tests/Fixtures/v7/README.md.
 */
$fixtureLogin = 'contract_fixture';
$fixtureEmail = 'contract-fixture@example.invalid';
$fixturePass = 'ContractTest123!';

return [
    'login / valid by username' => [
        'method' => 'POST',
        'path' => 'api/v7/security/login',
        'body' => ['login' => $fixtureLogin, 'password' => $fixturePass, 'os' => 'android'],
    ],
    'login / valid by email' => [
        'method' => 'POST',
        'path' => 'api/v7/security/login',
        'body' => ['login' => $fixtureEmail, 'password' => $fixturePass, 'os' => 'android'],
    ],
    'login / wrong password' => [
        'method' => 'POST',
        'path' => 'api/v7/security/login',
        'body' => ['login' => $fixtureLogin, 'password' => 'definitely-wrong', 'os' => 'android'],
    ],
    'login / unknown user' => [
        'method' => 'POST',
        'path' => 'api/v7/security/login',
        'body' => ['login' => 'nobody-here-at-all', 'password' => 'x', 'os' => 'ios'],
    ],
    'login / missing os' => [
        'method' => 'POST',
        'path' => 'api/v7/security/login',
        'body' => ['login' => $fixtureLogin, 'password' => $fixturePass],
    ],
    'login / missing password' => [
        'method' => 'POST',
        'path' => 'api/v7/security/login',
        'body' => ['login' => $fixtureLogin, 'os' => 'android'],
    ],
    'login / missing login' => [
        'method' => 'POST',
        'path' => 'api/v7/security/login',
        'body' => ['password' => $fixturePass, 'os' => 'android'],
    ],
    'login / GET instead of POST' => [
        'method' => 'GET',
        'path' => 'api/v7/security/login',
    ],
];
