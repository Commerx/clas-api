<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * A broadcast message: a community alert, a note to a property's subscribers,
 * or a hunting alert.
 *
 * One row per message, with a `notifications_users_mapping` row per recipient.
 * The category decides who the recipients are, which is the whole of the
 * fan-out logic — see App\Services\NotificationBroadcast.
 */
class Notification extends Model
{
    protected $table = 'notifications';

    public $timestamps = false;

    protected $guarded = [];

    protected $casts = [
        'id' => 'integer',
        'notification_category' => 'integer',
        'notification_sub_category' => 'integer',
        'land_id' => 'integer',
        'species_id' => 'integer',
        'hunters_count' => 'integer',
        'sender_id' => 'integer',
        'is_admin_notification' => 'boolean',
    ];

    /** Community Awareness: everyone near the property, with a push. */
    public const COMMUNITY_AWARENESS = 1;

    /** Subscriber Notification: the people who subscribed to that property. */
    public const SUBSCRIBER = 2;

    /** Hunting Alert: hunters whose active tags match the criteria. */
    public const HUNTING_ALERT = 3;

    public const CATEGORIES = [
        self::COMMUNITY_AWARENESS,
        self::SUBSCRIBER,
        self::HUNTING_ALERT,
    ];

    /**
     * Which notification setting governs delivery for this category.
     *
     * `all_notifications` is the master switch and is checked as well — see
     * NotificationBroadcast::wantsPush for why that is a change.
     */
    public const SETTING_FOR_CATEGORY = [
        self::COMMUNITY_AWARENESS => 'awareness_notifications',
        self::SUBSCRIBER => 'subscriber_notifications',
        self::HUNTING_ALERT => 'wildlife_notifications',
    ];
}
