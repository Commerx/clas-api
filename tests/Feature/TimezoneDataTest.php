<?php

namespace Tests\Feature;

use App\Services\SunTimes;
use DateTime;
use DateTimeZone;
use Tests\TestCase;

/**
 * The host's timezone database must be current.
 *
 * This is an environmental assumption, not a property of our code, and it is
 * the assumption that broke the live app. `SunTimes` resolves a coordinate to
 * a timezone *name* and lets PHP turn that into an offset — which is the
 * right design, because the name is stable and the offset is not. It is only
 * correct while PHP's timezone database knows the current rules.
 *
 * The old Yii application runs PHP 5.6 with a timezone database from
 * **2016.10**. In November 2026 five of the ten Canadian zones CLAS listings
 * fall in stopped observing the autumn clock change, and a 2016 database
 * still applies it:
 *
 *   zone                  2016.10    2026.5     live app
 *   America/Edmonton      -07:00     -06:00     an hour early
 *   America/Vancouver     -08:00     -07:00     an hour early
 *   America/Winnipeg      -06:00     -05:00     an hour early
 *   America/Whitehorse    -08:00     -07:00     an hour early
 *   America/Yellowknife   -07:00     -06:00     an hour early
 *   America/Toronto       -05:00     -05:00     correct
 *   America/Regina        -06:00     -06:00     correct
 *   America/Halifax       -04:00     -04:00     correct
 *   America/St_Johns      -03:30     -03:30     correct
 *   America/Iqaluit       -05:00     -05:00     correct
 *
 * So this API inherits the same bug the moment it is deployed onto a host
 * whose timezone database is behind, and nothing in the responses would say
 * so — sunrise would simply be an hour out, in the direction that sends
 * somebody onto the land before it is light.
 *
 * Hence a test rather than a line in a deployment document. It fails on the
 * stale host instead of after someone misses a sunrise.
 */
class TimezoneDataTest extends TestCase
{
    /**
     * The offsets that are true as of timezone database 2026.5, on a date
     * after the change took effect.
     *
     * When a rule changes again, this list is what needs updating — and the
     * failure will say which zone moved, which is the useful form for that
     * news to arrive in.
     */
    private const EXPECTED_OFFSETS = [
        'America/Edmonton' => '-06:00',
        'America/Vancouver' => '-07:00',
        'America/Winnipeg' => '-05:00',
        'America/Toronto' => '-05:00',
        'America/Regina' => '-06:00',
        'America/Halifax' => '-04:00',
        'America/St_Johns' => '-03:30',
        'America/Whitehorse' => '-07:00',
        'America/Yellowknife' => '-06:00',
        'America/Iqaluit' => '-05:00',
    ];

    /** A date comfortably after the November 2026 change. */
    private const AFTER_THE_CHANGE = '2026-11-09 12:00';

    public function test_the_host_timezone_database_knows_the_current_canadian_rules(): void
    {
        $wrong = [];

        foreach (self::EXPECTED_OFFSETS as $zone => $expected) {
            $actual = (new DateTime(self::AFTER_THE_CHANGE, new DateTimeZone($zone)))
                ->format('P');

            if ($actual !== $expected) {
                $wrong[] = sprintf('%s: expected %s, host says %s', $zone, $expected, $actual);
            }
        }

        $this->assertSame([], $wrong, implode(PHP_EOL, array_merge(
            ["This host's timezone database is out of date, or a rule has changed."],
            ['Sunrise and sunset would be reported with the wrong offset, which is'],
            ['what the live Yii app does today on a 2016 database.'],
            ['Current version on this host: '.timezone_version_get()],
            [''],
            $wrong,
        )));
    }

    /**
     * Stated separately, because the version is the thing an operator fixes.
     *
     * A host can only be as right as its database is recent, and a version
     * from before the change cannot know about it whatever the offsets
     * happen to say.
     */
    public function test_the_timezone_database_is_not_obviously_ancient(): void
    {
        $version = timezone_version_get();

        // Versions look like 2026.5. Anything before 2026.2 predates the
        // Canadian change and cannot be relied on for these zones.
        $this->assertMatchesRegularExpression('/^\d{4}\.\d+$/', $version,
            'Unrecognised timezone database version: '.$version);

        [$year] = explode('.', $version);

        $this->assertGreaterThanOrEqual(2026, (int) $year,
            "This host's timezone database is from {$version}. The live Yii app "
            .'runs 2016.10 and reports sunrise an hour early across five Canadian '
            .'provinces and territories as a result.');
    }

    /**
     * And the service built on it agrees, end to end.
     *
     * Ties the environmental assumption to the thing that depends on it, so
     * the connection is not left to be inferred.
     */
    public function test_sun_times_uses_the_current_offset(): void
    {
        // Waldron, Alberta — the coordinate the web map opens on.
        $times = app(SunTimes::class)->forLocation(49.90261265, -114.12588656);

        $this->assertSame('America/Edmonton', $times['timezone'],
            'Without a Google key this falls back to the app timezone, which '
            .'would make the rest of this test meaningless.');

        $rise = $times['today']['rise'];

        $this->assertNotNull($rise);

        // Not an assertion about a specific minute — that moves daily — but
        // about the hour band, which an hour's error would break. Sunrise in
        // southern Alberta is never at 07:xx in November on the current
        // rules; it was under the old ones, which is the bug.
        $this->assertMatchesRegularExpression('/^(0[6-9]|1[0-9]):/', $rise,
            'Sunrise came back as '.$rise.', which suggests the offset is wrong.');
    }
}
