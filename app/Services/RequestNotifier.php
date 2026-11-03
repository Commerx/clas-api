<?php

namespace App\Services;

use App\Mail\RequestCancelled;
use App\Mail\RequestDecided;
use App\Mail\RequestMovement;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

/**
 * Who gets told when a booking changes hands.
 *
 * The controllers hand over the request and the listing; this resolves the
 * people from the database, so a transition does not have to know who needs
 * telling. Both applications write the same database, so a decision made
 * through either one notifies the same person in the same words — otherwise
 * owners would see bookings change with no explanation.
 *
 * A missing or empty address is logged and skipped rather than thrown: a
 * booking that has already been accepted should not come back as a 500
 * because the notice could not be addressed.
 */
class RequestNotifier
{
    public function decided(object $request, object $land, string $decision): void
    {
        $requester = $this->person((int) $request->requester_id);

        if (! $this->canSendTo($requester, 'decided', $request)) {
            return;
        }

        $this->send($requester->email, new RequestDecided(
            request: $request,
            requesterName: $requester->name ?? '',
            propertyName: $land->land_name ?? '',
            requestDate: $this->formatDate($request->request_date),
            decision: strtolower($decision),
            // The listing's own rules, shown only on an acceptance.
            rules: $land->allowed_activities_text ?? null,
            // Empty source means the owner entered this listing themselves,
            // which is what decides the scan-in wording.
            source: ($land->source ?? '') === '' ? null : $land->source,
        ));
    }

    public function cancelled(object $request, object $land): void
    {
        $requester = $this->person((int) $request->requester_id);
        $owner = $this->person((int) ($land->owner ?? 0));

        if (! $this->canSendTo($owner, 'cancelled', $request)) {
            return;
        }

        $this->send($owner->email, new RequestCancelled(
            request: $request,
            requesterName: $requester->name ?? 'A visitor',
            ownerName: $owner->name ?? '',
            propertyName: $land->land_name ?? '',
            requestDate: $this->formatDate($request->request_date),
        ));
    }

    public function checkedIn(object $request, object $land): void
    {
        $this->movement($request, $land, 'in');
    }

    public function checkedOut(
        object $request,
        object $land,
        ?string $huntingCategory = null,
        ?string $huntingSubCategory = null,
    ): void {
        $this->movement($request, $land, 'out', $huntingCategory, $huntingSubCategory);
    }

    private function movement(
        object $request,
        object $land,
        string $direction,
        ?string $category = null,
        ?string $subCategory = null,
    ): void {
        $requester = $this->person((int) $request->requester_id);
        $owner = $this->person((int) ($land->owner ?? 0));

        if (! $this->canSendTo($owner, 'checked '.$direction, $request)) {
            return;
        }

        $this->send($owner->email, new RequestMovement(
            request: $request,
            requesterName: $requester->name ?? 'A visitor',
            ownerName: $owner->name ?? '',
            propertyName: $land->land_name ?? '',
            movement: $direction,
            huntingCategory: $category,
            huntingSubCategory: $subCategory,
        ));
    }

    /** The user's email and display name, or null when there is no such user. */
    private function person(int $userId): ?object
    {
        if ($userId <= 0) {
            return null;
        }

        return DB::table('user')
            ->leftJoin('profile', 'profile.user_id', '=', 'user.id')
            ->where('user.id', $userId)
            ->select(['user.id', 'user.email', 'profile.name'])
            ->first();
    }

    private function canSendTo(?object $person, string $event, object $request): bool
    {
        if ($person !== null && ! empty($person->email)) {
            return true;
        }

        Log::warning('Booking notification not sent: no address for the recipient.', [
            'event' => $event,
            'request_id' => $request->request_id ?? null,
        ]);

        return false;
    }

    private function send(string $to, object $mailable): void
    {
        Mail::to($to)->send($mailable);
    }

    private function formatDate(?string $date): string
    {
        return $date ? date('M d, Y', strtotime($date)) : '';
    }
}
