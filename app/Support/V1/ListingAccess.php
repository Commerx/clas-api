<?php

namespace App\Support\V1;

use Illuminate\Support\Facades\DB;

/**
 * Who may act on a listing.
 *
 * One definition, used everywhere. It was three copies before, and the copies
 * drifted: two of them accepted a *pending* manager invitation as though it
 * were an accepted one, which would have let someone who had been invited but
 * never accepted read a listing's bookings. There are 7 pending invitations in
 * the database.
 *
 * The rule, as the old application defined it in Land::isManager():
 *
 *   - the owner always qualifies
 *   - a land manager qualifies only when their row is `status = 'accepted'`
 *
 * Reading uses `canManage`. Writing stays owner-only — `isOwner` — which is
 * what the old application enforced on the block and decision endpoints, and
 * write behaviour has to stay identical while both share the database.
 */
class ListingAccess
{
    /** Owner, or a manager whose invitation has been accepted. */
    public static function canManage(int $userId, int $landId): bool
    {
        return self::accessibleQuery($userId)
            ->where('land.land_id', $landId)
            ->exists();
    }

    /** Owner only. */
    public static function isOwner(int $userId, int $landId): bool
    {
        return DB::table('land')
            ->where('land_id', $landId)
            ->where('owner', $userId)
            ->exists();
    }

    /**
     * Every listing the user owns or manages.
     *
     * Active and inactive both count: an owner still needs to see the requests
     * against a listing they have taken down.
     *
     * @return list<int>
     */
    public static function manageableListingIds(int $userId): array
    {
        return self::accessibleQuery($userId)
            ->whereIn('land.active', ['Y', 'N'])
            ->distinct()
            ->pluck('land.land_id')
            ->map(fn ($id) => (int) $id)
            ->all();
    }

    private static function accessibleQuery(int $userId)
    {
        return DB::table('land')
            ->leftJoin('land_managers', function ($join) use ($userId) {
                $join->on('land_managers.land_id', '=', 'land.land_id')
                    ->where('land_managers.user_id', '=', $userId)
                    // Only an accepted invitation grants access; `pending`
                    // means the invitation was sent and never taken up.
                    ->where('land_managers.status', '=', 'accepted');
            })
            ->where(function ($q) use ($userId) {
                $q->where('land.owner', $userId)
                    ->orWhereNotNull('land_managers.user_id');
            });
    }
}
