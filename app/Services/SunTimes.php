<?php

namespace App\Services;

use DateTime;
use DateTimeZone;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Sunrise and sunset for a location, so users can plan travel around daylight.
 *
 * The old implementation made two external calls on every request — Google's
 * timezone API and sunrise-sunset.org — with TLS verification switched off and
 * a die() on any non-200, which truncated the JSON mid-response. It also
 * discarded the offset Google returned and kept only the zone name, then let
 * PHP resolve the offset locally; on PHP 5.6, whose bundled timezone database
 * is from 2016, that put Alberta an hour out. Sunrise was reported an hour
 * early, which for this feature is the unsafe direction.
 *
 * Here the sun times come from PHP's own date_sun_info(), which agreed with
 * sunrise-sunset.org to the second on every value tested, so no network call
 * is needed for them. Only the zone name needs looking up, and a parcel of
 * land does not move: the answer is cached per rounded coordinate and reused.
 * The offset then comes from the current timezone database.
 */
class SunTimes
{
    /** ~1km of precision is far finer than a timezone boundary needs. */
    private const COORD_PRECISION = 2;

    public function forLocation(float $lat, float $lng): array
    {
        $zone = new DateTimeZone($this->timezoneFor($lat, $lng));

        return [
            'timezone' => $zone->getName(),
            'today' => $this->dayFor(new DateTime('today', $zone), $lat, $lng, $zone),
            'tomorrow' => $this->dayFor(new DateTime('tomorrow', $zone), $lat, $lng, $zone),
        ];
    }

    private function dayFor(DateTime $day, float $lat, float $lng, DateTimeZone $zone): array
    {
        $info = date_sun_info($day->getTimestamp(), $lat, $lng);

        return [
            'rise' => $this->localise($info['sunrise'] ?? false, $zone),
            'set' => $this->localise($info['sunset'] ?? false, $zone),
        ];
    }

    /**
     * date_sun_info returns false for sunrise or sunset above the Arctic
     * circle when the sun does not cross the horizon that day — which is
     * reachable territory for this application, so it is a real case and
     * not a defensive flourish.
     */
    private function localise(int|bool $timestamp, DateTimeZone $zone): ?string
    {
        if ($timestamp === false || $timestamp === true) {
            return null;
        }

        return (new DateTime('@'.$timestamp))
            ->setTimezone($zone)
            ->format('H:i:s');
    }

    /**
     * Google is authoritative and current; it is just not worth asking twice
     * for the same place. Falls back to the application timezone so a lookup
     * failure degrades to a plausible answer rather than an error, and logs
     * it so the fallback does not pass unnoticed.
     */
    private function timezoneFor(float $lat, float $lng): string
    {
        $key = sprintf('tz:%s,%s',
            round($lat, self::COORD_PRECISION),
            round($lng, self::COORD_PRECISION));

        return Cache::rememberForever($key, function () use ($lat, $lng) {
            $apiKey = config('services.google.timezone_key');

            if (! $apiKey) {
                return config('app.timezone');
            }

            try {
                $response = Http::timeout(8)
                    ->get('https://maps.googleapis.com/maps/api/timezone/json', [
                        'location' => $lat.','.$lng,
                        'timestamp' => time(),
                        'key' => $apiKey,
                    ]);

                if ($response->successful() && $response->json('status') === 'OK') {
                    return $response->json('timeZoneId');
                }

                Log::warning('Timezone lookup failed', [
                    'lat' => $lat, 'lng' => $lng,
                    'status' => $response->json('status') ?? $response->status(),
                ]);
            } catch (\Throwable $e) {
                Log::warning('Timezone lookup threw', [
                    'lat' => $lat, 'lng' => $lng, 'message' => $e->getMessage(),
                ]);
            }

            return config('app.timezone');
        });
    }
}
