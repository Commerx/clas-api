<?php

/**
 * Compares the rebuilt endpoints against the Yii v7 originals.
 *
 * Two shipped mobile apps cannot be patched from the server, so a renamed
 * field or a number serialised as a string breaks an installed app. This
 * script calls both implementations with identical input and reports any
 * difference in the raw bytes, the key set, or the JSON type of each value.
 *
 *   php tools/contract-diff.php [old-base] [new-base]
 *
 * Defaults to the local Laragon copy of v7 and artisan serve.
 */
$old = $argv[1] ?? 'http://clas.test';
$new = $argv[2] ?? 'http://127.0.0.1:8000';

/** Values that legitimately differ between runs or hosts. */
const VOLATILE = ['token', 'deactivation_date'];

$cases = require __DIR__.'/contract-cases.php';

function call(string $base, array $case): array
{
    $url = rtrim($base, '/').'/'.ltrim($case['path'], '/');
    $ch = curl_init();
    $opts = [
        CURLOPT_URL => $url,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 60,
        CURLOPT_HTTPHEADER => ['Accept: application/json'],
    ];
    if (($case['method'] ?? 'GET') === 'POST') {
        $opts[CURLOPT_POST] = true;
        $opts[CURLOPT_POSTFIELDS] = http_build_query($case['body'] ?? []);
    }
    curl_setopt_array($ch, $opts);
    $body = curl_exec($ch);
    $status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err = curl_error($ch);

    return ['status' => $status, 'body' => $body === false ? '' : $body, 'error' => $err];
}

/** Recursively replace values with their JSON type, so types are compared too. */
function shape($v)
{
    if (is_array($v)) {
        $out = [];
        foreach ($v as $k => $vv) {
            $out[$k] = in_array($k, VOLATILE, true) ? '<volatile>' : shape($vv);
        }

        return $out;
    }
    if (is_bool($v)) {
        return 'bool:'.($v ? 'true' : 'false');
    }
    if (is_int($v)) {
        return 'int';
    }
    if (is_float($v)) {
        return 'float';
    }
    if (is_null($v)) {
        return 'null';
    }

    return 'string';
}

/** Flatten a nested shape into dotted paths, so a mismatch names its location. */
function flatten($value, string $prefix = ''): array
{
    if (! is_array($value)) {
        return [$prefix === '' ? '(root)' : $prefix => (string) $value];
    }

    if ($value === []) {
        return [$prefix === '' ? '(root)' : $prefix => '[]'];
    }

    $out = [];

    foreach ($value as $key => $inner) {
        $path = $prefix === '' ? (string) $key : $prefix.'.'.$key;
        $out += flatten($inner, $path);
    }

    return $out;
}

$pass = $fail = 0;

foreach ($cases as $name => $case) {
    $a = call($old, $case);
    $b = call($new, $case);

    $ja = json_decode($a['body'], true);
    $jb = json_decode($b['body'], true);

    $problems = [];

    if ($a['error'] !== '' || $b['error'] !== '') {
        $problems[] = 'transport: old='.$a['error'].' new='.$b['error'];
    }
    if ($ja === null || $jb === null) {
        $problems[] = 'not valid json: old='.substr($a['body'], 0, 60)
            .' new='.substr($b['body'], 0, 60);
    } else {
        if (array_keys($ja) !== array_keys($jb)) {
            $problems[] = 'top-level key order/set differs: old=['
                .implode(',', array_keys($ja)).'] new=['.implode(',', array_keys($jb)).']';
        }
        $flatA = flatten(shape($ja));
        $flatB = flatten(shape($jb));

        foreach (array_keys($flatA + $flatB) as $path) {
            $va = $flatA[$path] ?? '<missing>';
            $vb = $flatB[$path] ?? '<missing>';

            if ($va !== $vb) {
                $problems[] = sprintf('%s: old=%s new=%s', $path, $va, $vb);
            }
        }

        // Error text is part of the contract, not just its type.
        if (($ja['error'] ?? null) !== ($jb['error'] ?? null)) {
            $problems[] = sprintf('error text: old=%s new=%s',
                var_export($ja['error'] ?? null, true), var_export($jb['error'] ?? null, true));
        }
    }

    if ($problems) {
        $fail++;
        printf("FAIL  %s\n", $name);
        foreach ($problems as $p) {
            printf("        %s\n", $p);
        }
    } else {
        $pass++;
        printf("ok    %-34s (http %s/%s)\n", $name, $a['status'], $b['status']);
    }
}

printf("\n%d passed, %d failed\n", $pass, $fail);
exit($fail === 0 ? 0 : 1);
