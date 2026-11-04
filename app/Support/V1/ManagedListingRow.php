<?php

namespace App\Support\V1;

use Illuminate\Support\Collection;

/**
 * A `land` row as its owner or manager sees it.
 *
 * Wider than `ListingRow`'s public view, because the management screens edit
 * fields the public listing never shows — the guide numbers, the supersede
 * addresses, the signage and shipping state, which step of the draft flow the
 * listing is on. Still an allowlist, and still a narrower one than the old
 * endpoint's `SELECT land.*`.
 *
 * Four columns are deliberately absent for everybody:
 *
 *   claim_code   the secret that lets whoever presents it take ownership of a
 *                listing. Set on 6,131 rows. The old `manage-listings/detail`
 *                published it to any authenticated caller, for any listing,
 *                because that endpoint had no ownership check at all.
 *   customer_id
 *   order_id
 *   txn_number   payment processor identifiers. Nothing in the app needs them
 *                and they belong to the payment records, not the listing.
 *
 * If a management screen turns out to need the claim code, it wants an
 * endpoint of its own, restricted to the owner, rather than a field on every
 * listing response.
 */
class ManagedListingRow
{
    /**
     * Everything `manage-listings/detail` returns.
     *
     * Table-qualified because the queries join photos and managers.
     */
    public const COLUMNS = [
        'land.land_id', 'land.owner', 'land.land_name', 'land.land_keywords',
        'land.land_description', 'land.address', 'land.contact_email',
        'land.contact_phone', 'land.coordinates', 'land.land_lat',
        'land.land_lng', 'land.total_deeded', 'land.access_points',
        'land.signage_type', 'land.required_signages',
        'land.additional_signages', 'land.signage_shipping_info',
        'land.recreation_access_allowed', 'land.people_allowed',
        'land.requests_allowed', 'land.allowed_activities',
        'land.allowed_activities_text', 'land.key_features',
        'land.features_list', 'land.barcode', 'land.auto_approve_requests',
        'land.allow_guide', 'land.guide_number_1', 'land.guide_1_auto_approval',
        'land.guide_number_2', 'land.guide_2_auto_approval',
        'land.guide_number_3', 'land.guide_3_auto_approval',
        'land.allow_unlimited_access', 'land.supersede_emails_1',
        'land.supersede_emails_2', 'land.created_at', 'land.updated_at',
        'land.current_step', 'land.status', 'land.is_first_nations',
        'land.is_open_property', 'land.kml_file_exists', 'land.fn_land_code',
        'land.is_rid_land', 'land.visibility_status', 'land.active',
        'land.payment_status', 'land.signage_amount', 'land.signage_promocode',
        'land.signage_discount_amount', 'land.signage_shipping_method',
        'land.signage_shipping_amount', 'land.total_amount', 'land.paid_amount',
        'land.kml', 'land.geofencing', 'land.geo_json',
        'land.conservationSiteID', 'land.siteData', 'land.source',
        'land.map_pdf', 'land.camp_sites', 'land.area', 'land.points',
        'land.fireban_status_id',
    ];

    /** What the list of a user's own listings carries. */
    public const SUMMARY_COLUMNS = [
        'land.land_id', 'land.land_name', 'land.address', 'land.payment_status',
        'land.active', 'land.is_rid_land', 'land.fireban_status_id',
        'land.visibility_status', 'land.current_step', 'land.created_at',
    ];

    private const INT_COLUMNS = ['current_step', 'required_signages', 'people_allowed'];

    /**
     * @param  array<string,mixed>  $row
     * @return array<string,mixed>
     */
    public static function format(array $row, Collection $keyFeatures, bool $withGeometry = true): array
    {
        $row = ListingRow::format($row, $keyFeatures, $withGeometry);

        foreach (self::INT_COLUMNS as $column) {
            if (array_key_exists($column, $row) && $row[$column] !== null) {
                $row[$column] = (int) $row[$column];
            }
        }

        foreach (['signage_amount', 'signage_discount_amount', 'signage_shipping_amount',
            'total_amount', 'paid_amount'] as $column) {
            if (array_key_exists($column, $row) && $row[$column] !== null) {
                $row[$column] = (float) $row[$column];
            }
        }

        return $row;
    }

    /**
     * A manager as the owner's screen shows them.
     *
     * `user_id` is included because the app needs to tell an invitation that
     * matched an existing account from one that did not; the invited person's
     * email is already theirs to see, since the owner typed it.
     */
    public static function manager(object $manager): array
    {
        return [
            'id' => (int) $manager->id,
            'first_name' => $manager->first_name,
            'last_name' => $manager->last_name,
            'user_email' => $manager->user_email,
            'user_id' => $manager->user_id === null ? null : (int) $manager->user_id,
            'status' => $manager->status,
            'created' => $manager->created,
        ];
    }

    /** A signage line on a listing. */
    public static function signage(object $signage): array
    {
        return [
            'id' => (int) $signage->id,
            'signage_type' => (int) $signage->signage_type,
            'qty' => (int) $signage->qty,
            'price' => (float) $signage->price,
            'created_at' => $signage->created_at,
        ];
    }

    /** A photo row, with the derived names the app expects beside it. */
    public static function photo(object $photo): array
    {
        return [
            'photo_id' => (int) $photo->photo_id,
            'land_id' => (int) $photo->land_id,
            'photo_name' => $photo->photo_name,
            'is_titlephoto' => $photo->is_titlephoto,
            'size' => (int) $photo->size,
            'type' => $photo->type,
        ];
    }
}
