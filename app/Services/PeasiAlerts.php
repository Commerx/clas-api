<?php

namespace App\Services;

use App\Models\Notification;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Forwarding an alert to PEASI, the provincial emergency alerting service.
 *
 * Posts a CAP 1.2 document, the same shape the old application sent. What is
 * different is everything around it:
 *
 *  - the URL was `https://mobile-teams-dev.peasi.ca/alert-checkin/event/`,
 *    hardcoded, so production was talking to a development endpoint
 *  - the bearer token was a literal in the controller
 *  - the response was discarded, so a rejection looked like a success
 *  - it was reached from notifications/submit only, not from the dedicated
 *    hunting-alert endpoint, so whether an alert was forwarded depended on
 *    which endpoint the app happened to call
 *
 * Disabled unless a URL and token are configured. Confirm the environment and
 * credentials with PEASI before turning it on: forwarding a user-submitted
 * message into an emergency alerting system is not something to enable by
 * default, and the previous destination was explicitly a dev host.
 */
class PeasiAlerts
{
    /** @var list<array> documents this would have posted, for tests */
    public array $forwarded = [];

    public function forward(Notification $notification, ?object $land = null): bool
    {
        $document = $this->capDocument($notification, $land);

        $this->forwarded[] = $document;

        if (! config('notifications.peasi.enabled')) {
            return false;
        }

        $url = (string) config('notifications.peasi.url');
        $token = (string) config('notifications.peasi.token');

        if ($url === '' || $token === '') {
            Log::warning('PEASI forwarding is enabled but not configured.');

            return false;
        }

        try {
            $response = Http::withHeaders(['Authorization' => 'Token '.$token])
                ->timeout((int) config('notifications.peasi.timeout'))
                ->post($url, $document);
        } catch (\Throwable $e) {
            report($e);

            return false;
        }

        if ($response->failed()) {
            // The old code could not have told you this happened.
            Log::warning('PEASI rejected an alert', [
                'status' => $response->status(),
                'notification' => $notification->id,
            ]);

            return false;
        }

        return true;
    }

    /**
     * The CAP 1.2 payload.
     *
     * Kept field for field from the old implementation, including the
     * `CLAS-USER-` identifier prefix and the "other" event code, so PEASI
     * sees what it has always seen from this system. The severity set is the
     * one the old code selected: it defined drill, advisory and critical
     * values and used critical for every alert.
     */
    private function capDocument(Notification $notification, ?object $land): array
    {
        $message = (string) $notification->message_body;

        return [
            'alert_id' => 'CLAS-USER-'.$notification->id,
            'cap_content' => [
                'cap' => 'urn:oasis:names:tc:emergency:cap:1.2',
                'alert' => [
                    'status' => 'Actual',
                    'info' => [
                        [
                            'category' => ['Other'],
                            'language' => 'en-CA',
                            'eventCode' => [
                                [
                                    'value' => 'other',
                                    'valueName' => 'profile:CAP-CP:Event:0.4',
                                ],
                            ],
                            'severity' => 'Extreme',
                            'certainty' => 'Observed',
                            'urgency' => 'Immediate',
                            'headline' => 'Major Crime Report near '.($land->land_name ?? 'a property'),
                            'description' => $message,
                            'instruction' => $message,
                        ],
                    ],
                ],
            ],
        ];
    }
}
