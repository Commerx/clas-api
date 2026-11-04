<?php

namespace App\Services;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Sending a push message to one device.
 *
 * The old application posted to `https://fcm.googleapis.com/fcm/send` with a
 * server key written into the controller. That is FCM's legacy HTTP API,
 * retired by Google in July 2024, so every one of those calls has been
 * failing since — and nothing read the response, so it failed silently. A
 * commented-out line next to it points at the v1 endpoint, so the migration
 * was started and left.
 *
 * This speaks v1, which authenticates with a service account rather than a
 * static key: a signed JWT is exchanged for a short-lived access token, which
 * is cached. It also verifies TLS, which the old code explicitly turned off.
 *
 * Until credentials are configured the driver is `log`. That matters for the
 * rest of this feature: the fan-out decides recipients, writes their rows and
 * asks this class to deliver, and all of that is testable and observable
 * whether or not a Firebase project is wired up. A failure to deliver never
 * propagates — one unreachable device must not fail a broadcast to hundreds.
 */
class PushSender
{
    private const SCOPE = 'https://www.googleapis.com/auth/firebase.messaging';

    private const TOKEN_URL = 'https://oauth2.googleapis.com/token';

    /** @var list<array{token: string, payload: array}> what `log` recorded */
    public array $sent = [];

    /**
     * @param  array<string,string|int>  $data  carried through to the app
     */
    public function send(string $deviceToken, string $title, string $body, array $data = []): bool
    {
        if ($deviceToken === '') {
            return false;
        }

        $payload = [
            'title' => $title,
            'body' => $body,
            'data' => array_map(static fn ($v) => (string) $v, $data),
        ];

        return match (config('notifications.push.driver')) {
            'fcm' => $this->sendViaFcm($deviceToken, $payload),
            'null' => true,
            default => $this->record($deviceToken, $payload),
        };
    }

    private function record(string $deviceToken, array $payload): bool
    {
        $this->sent[] = ['token' => $deviceToken, 'payload' => $payload];

        Log::info('push (not delivered: driver is log)', [
            // Enough to tell which message went where, without putting a
            // device token in the log in full.
            'device' => substr($deviceToken, 0, 8).'...',
            'title' => $payload['title'],
            'data' => $payload['data'],
        ]);

        return true;
    }

    private function sendViaFcm(string $deviceToken, array $payload): bool
    {
        $project = (string) config('notifications.push.fcm.project_id');
        $accessToken = $this->accessToken();

        if ($project === '' || $accessToken === null) {
            Log::warning('push skipped: FCM driver selected but not configured.');

            return false;
        }

        try {
            $response = Http::withToken($accessToken)
                ->timeout(10)
                ->post("https://fcm.googleapis.com/v1/projects/{$project}/messages:send", [
                    'message' => [
                        'token' => $deviceToken,
                        'notification' => [
                            'title' => $payload['title'],
                            'body' => $payload['body'],
                        ],
                        'data' => $payload['data'],
                    ],
                ]);
        } catch (\Throwable $e) {
            report($e);

            return false;
        }

        if ($response->failed()) {
            // Unlike the old code, say so. A 404 UNREGISTERED means the
            // device uninstalled and its row should eventually be dropped.
            Log::warning('push rejected by FCM', [
                'status' => $response->status(),
                'error' => $response->json('error.status'),
            ]);

            return false;
        }

        return true;
    }

    /**
     * A short-lived access token for the service account, cached.
     *
     * Signed locally with the service account key, so this needs no SDK.
     */
    private function accessToken(): ?string
    {
        $path = (string) config('notifications.push.fcm.credentials');

        if ($path === '' || ! is_readable($path)) {
            return null;
        }

        return Cache::remember('fcm.access_token', now()->addMinutes(50), function () use ($path) {
            $service = json_decode((string) file_get_contents($path), true);

            if (! is_array($service) || ! isset($service['client_email'], $service['private_key'])) {
                Log::warning('push skipped: FCM credentials file is not a service account key.');

                return null;
            }

            $now = time();
            $claims = [
                'iss' => $service['client_email'],
                'scope' => self::SCOPE,
                'aud' => self::TOKEN_URL,
                'iat' => $now,
                'exp' => $now + 3600,
            ];

            $jwt = $this->signJwt($claims, (string) $service['private_key']);

            if ($jwt === null) {
                return null;
            }

            $response = Http::asForm()->timeout(10)->post(self::TOKEN_URL, [
                'grant_type' => 'urn:ietf:params:oauth:grant-type:jwt-bearer',
                'assertion' => $jwt,
            ]);

            return $response->successful() ? $response->json('access_token') : null;
        });
    }

    private function signJwt(array $claims, string $privateKey): ?string
    {
        $segments = [
            $this->base64Url(json_encode(['alg' => 'RS256', 'typ' => 'JWT'])),
            $this->base64Url(json_encode($claims)),
        ];

        $input = implode('.', $segments);
        $signature = '';

        if (! openssl_sign($input, $signature, $privateKey, OPENSSL_ALGO_SHA256)) {
            Log::warning('push skipped: could not sign the FCM assertion.');

            return null;
        }

        return $input.'.'.$this->base64Url($signature);
    }

    private function base64Url(string $value): string
    {
        return rtrim(strtr(base64_encode($value), '+/', '-_'), '=');
    }
}
