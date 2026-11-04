<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * One recipient of one notification.
 *
 * This is also the read receipt: `is_read` is per recipient, and the unread
 * count every screen shows is a count of these rows. `tag_id` is set only for
 * a hunting alert, and records which of the recipient's tags matched.
 */
class NotificationUserMapping extends Model
{
    protected $table = 'notifications_users_mapping';

    public $timestamps = false;

    protected $guarded = [];

    protected $casts = [
        'id' => 'integer',
        'notification_id' => 'integer',
        'user_id' => 'integer',
        'tag_id' => 'integer',
        'is_reported' => 'boolean',
        'is_read' => 'boolean',
    ];
}
