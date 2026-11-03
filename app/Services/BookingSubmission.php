<?php

namespace App\Services;

use DateInterval;
use DatePeriod;
use DateTime;
use Illuminate\Support\Facades\DB;

/**
 * Creating access requests.
 *
 * One request row per day between the start and end dates, after checking that
 * each of those days can actually take it. The rules are the substance here,
 * and they are the old application's — this writes to the same database, so a
 * booking made through either must be subject to the same limits or the two
 * will disagree about whether a property is full.
 *
 * Three kinds of caller get different treatment:
 *
 *  - a **supersede** caller, whose email is listed on the property and which
 *    has unlimited access enabled: skips the availability checks entirely
 *  - a **guide**, whose guide number is listed on the property: exempt from
 *    the per-day duplicate and the two-per-day limits
 *  - everyone else: subject to all of it
 *
 * A property imported from a provincial dataset that carries public contact
 * details also skips availability, since nobody is managing its capacity.
 */
class BookingSubmission
{
    /** Request reason to the column holding its limit on an open property. */
    private const ACTIVITY_LIMITS = [
        'Foot Access' => 'foot',
        'Hunting' => 'hunting',
        'Fishing' => 'fishing',
        'OHV' => 'ohv',
        'Horse' => 'horse',
        'SnowMobile' => 'snowmobile',
    ];

    /** Statuses that occupy a place on a given day. */
    private const OCCUPYING = ['Accepted', 'Checked In', 'Checked Out'];

    /** Statuses that count as the caller already holding a booking. */
    private const HELD_BY_CALLER = ['Accepted', 'Pending', 'Checked In'];

    private const REQUESTS_PER_DAY = 2;

    /**
     * @return array{0: list<int>, 1: ?string} created request ids, or a refusal
     */
    public function submit(object $user, object $land, array $input): array
    {
        $days = $this->days($input['start'], $input['end']);

        if ($days === []) {
            return [[], 'The end date cannot be before the start date.'];
        }

        $standing = $this->standing($user, $land);

        foreach ($days as $day) {
            if ($refusal = $this->refuseDay($user, $land, $day, $input, $standing)) {
                return [[], $refusal];
            }
        }

        return [$this->create($user, $land, $days, $input, $standing), null];
    }

    /**
     * Where this caller stands with this property: guide, supersede, and
     * whether the property manages its own capacity at all.
     *
     * @return array{guide: bool, supersede: bool, autoApprove: bool, unmanaged: bool}
     */
    private function standing(object $user, object $land): array
    {
        $guideNumbers = $this->guideNumbers($land);
        $supersedeEmails = $this->supersedeEmails($land);

        $callerGuideNumber = (string) ($user->guide_number ?? '');

        $isGuide = $guideNumbers !== []
            && ($land->allow_guide ?? '') === 'Yes'
            && ($user->guide ?? '') === 'Yes'
            && array_key_exists($callerGuideNumber, $guideNumbers);

        $isSupersede = $supersedeEmails !== []
            && ($land->allow_unlimited_access ?? '') === 'Yes'
            && array_key_exists(strtolower((string) $user->email), $supersedeEmails);

        // A listing imported from a provincial dataset that publishes contact
        // details has no owner managing its calendar, so availability does not
        // apply to it.
        $unmanaged = ($land->source ?? '') !== ''
            && (($land->contact_email ?? '') !== '' || ($land->contact_phone ?? '') !== '');

        $autoApprove = ($land->auto_approve_requests ?? '') === 'Yes'
            || ($isGuide && ($guideNumbers[$callerGuideNumber] ?? null) === 'Yes')
            || ($isSupersede && ($supersedeEmails[strtolower((string) $user->email)] ?? null) === 'Yes');

        return [
            // A supersede caller is treated as a guide as well, as before.
            'guide' => $isGuide || $isSupersede,
            'supersede' => $isSupersede,
            'autoApprove' => $autoApprove,
            'unmanaged' => $unmanaged,
        ];
    }

    /** @return array<string,string> guide number => auto-approval flag */
    private function guideNumbers(object $land): array
    {
        $numbers = [];

        foreach ([1, 2, 3] as $slot) {
            $number = $land->{'guide_number_'.$slot} ?? null;

            if ($number !== null && $number !== '') {
                $numbers[(string) $number] = $land->{'guide_'.$slot.'_auto_approval'} ?? null;
            }
        }

        return $numbers;
    }

    /**
     * @return array<string,string> email => auto-approval flag
     *
     * The first list auto-approves, the second does not. Both grant the
     * supersede exemption.
     */
    private function supersedeEmails(object $land): array
    {
        $emails = [];

        foreach (['supersede_emails_1' => 'Yes', 'supersede_emails_2' => 'No'] as $column => $approval) {
            foreach (explode(',', (string) ($land->{$column} ?? '')) as $email) {
                $email = strtolower(trim($email));

                if ($email !== '') {
                    $emails[$email] = $approval;
                }
            }
        }

        return $emails;
    }

    /** Every date from start to end inclusive. @return list<string> */
    private function days(string $start, string $end): array
    {
        $begin = new DateTime($start);
        $finish = new DateTime($end);

        if ($finish < $begin) {
            return [];
        }

        $period = new DatePeriod(
            $begin,
            DateInterval::createFromDateString('1 day'),
            (clone $finish)->modify('+1 day'),
        );

        return array_map(fn ($d) => $d->format('Y-m-d'), iterator_to_array($period));
    }

    /** The reason this day cannot be booked, or null. */
    private function refuseDay(
        object $user,
        object $land,
        string $day,
        array $input,
        array $standing,
    ): ?string {
        $pretty = date('M d, Y', strtotime($day));

        if (! $standing['supersede'] && ! $standing['unmanaged']) {
            if ($refusal = $this->refuseOnAvailability($land, $day, $pretty, $input)) {
                return $refusal;
            }
        }

        // These two apply to everyone except guides, including on a property
        // whose availability is not managed.
        if ($standing['guide']) {
            return null;
        }

        $alreadyHere = DB::table('requests')
            ->where('land_id', $land->land_id)
            ->where('request_date', $day)
            ->where('requester_id', $user->id)
            ->whereIn('status', self::HELD_BY_CALLER)
            ->count();

        if ($alreadyHere > 0) {
            return 'You have already requested this land for the day '.$pretty;
        }

        $thatDay = DB::table('requests')
            ->where('request_date', $day)
            ->where('requester_id', $user->id)
            ->whereIn('status', self::HELD_BY_CALLER)
            ->count();

        if ($thatDay >= self::REQUESTS_PER_DAY) {
            return sprintf(
                'You have already reached your requesting limit (%d per day) for the day %s',
                self::REQUESTS_PER_DAY,
                $pretty,
            );
        }

        return null;
    }

    private function refuseOnAvailability(object $land, string $day, string $pretty, array $input): ?string
    {
        $blocked = DB::table('land_blocked_days')
            ->where('land_id', $land->land_id)
            ->where('blocked_date', $day)
            ->first();

        $isOpen = (bool) $land->is_open_property;

        if ($blocked !== null && ! $isOpen) {
            return 'This land is not available on the day '.$pretty;
        }

        $existing = DB::table('requests')
            ->where('land_id', $land->land_id)
            ->where('request_date', $day)
            ->whereIn('status', self::OCCUPYING)
            ->get(['reason', 'people_count', 'units_count']);

        if ($isOpen) {
            return $this->refuseOpenProperty($land, $blocked, $existing, $day, $pretty, $input);
        }

        // A private property counts people, and a booking counts as at least one.
        $taken = $existing->sum(fn ($row) => max(1, (int) $row->people_count));

        return $taken >= (int) $land->people_allowed
            ? 'This land is fully booked on '.$pretty
            : null;
    }

    /**
     * An open property has a separate allowance per activity, and counts
     * vehicles rather than people. A blocked day on an open property does not
     * close it — it replaces the allowances for that day.
     */
    private function refuseOpenProperty(
        object $land,
        ?object $blocked,
        $existing,
        string $day,
        string $pretty,
        array $input,
    ): ?string {
        $reason = (string) $input['reason'];
        $column = self::ACTIVITY_LIMITS[$reason] ?? null;

        $allowed = max(1, (int) $land->people_allowed);

        if ($column !== null) {
            $allowed = $this->activityAllowance($land, $blocked, $column) ?? $allowed;
        }

        $taken = $existing
            ->filter(fn ($row) => $row->reason === $reason)
            ->sum(fn ($row) => max(1, (int) $row->units_count));

        $wanted = max(1, (int) ($input['units_count'] ?? 1));

        if ($taken + $wanted <= $allowed) {
            return null;
        }

        $remaining = $allowed - $taken;

        return match (true) {
            $allowed === 0 => sprintf('This land is not available for %s on %s', $reason, $pretty),
            $remaining <= 0 => sprintf('This land is fully booked for %s on %s', $reason, $pretty),
            default => sprintf('%d unit(s) only available for %s on %s', $remaining, $reason, $pretty),
        };
    }

    /** The day's override if the day is blocked, otherwise the standing limit. */
    private function activityAllowance(object $land, ?object $blocked, string $column): ?int
    {
        if ($blocked !== null && ! empty($blocked->open_property_limits)) {
            $limits = json_decode($blocked->open_property_limits, true);

            if (is_array($limits) && array_key_exists($column, $limits)) {
                return (int) $limits[$column];
            }
        }

        $limits = DB::table('land_access_limits')->where('land_id', $land->land_id)->first();

        return $limits !== null && isset($limits->{$column}) ? (int) $limits->{$column} : null;
    }

    /** @return list<int> the ids created */
    private function create(object $user, object $land, array $days, array $input, array $standing): array
    {
        $ids = [];

        DB::transaction(function () use ($user, $land, $days, $input, $standing, &$ids) {
            foreach ($days as $day) {
                $row = [
                    'reason' => $input['reason'],
                    'description' => $input['description'] ?? '',
                    'people_count' => ($input['people_count'] ?? '') !== '' ? (int) $input['people_count'] : null,
                    'people_details' => $input['people_details'] ?? '',
                    'request_date' => $day,
                    'land_id' => $land->land_id,
                    'requester_id' => $user->id,
                    'requested_at' => time(),
                ];

                if ($land->is_open_property) {
                    $row['units_count'] = ($input['units_count'] ?? '') !== '' ? (int) $input['units_count'] : null;

                    if (! empty($input['ohv_details'])) {
                        $row['ohv_details'] = $input['ohv_details'];
                    }

                    if (! empty($input['visitors'])) {
                        $row['visitor_details'] = is_array($input['visitors'])
                            ? json_encode($input['visitors'])
                            : $input['visitors'];
                    }
                }

                // A supersede request is flagged as such rather than as a
                // guide request, even though it carries the guide exemptions.
                if ($standing['supersede']) {
                    $row['is_supersede_request'] = 1;
                } elseif ($standing['guide']) {
                    $row['is_guide_request'] = 1;
                }

                if ($standing['autoApprove']) {
                    $row['status'] = 'Accepted';
                    $row['decision_at'] = time();
                }

                $ids[] = (int) DB::table('requests')->insertGetId($row);
            }
        });

        return $ids;
    }
}
