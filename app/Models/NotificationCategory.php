<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * The existing `notification_categories` table.
 *
 * v7 returned these through Yii ActiveRecord, which casts integer columns
 * from the schema, so `id` and `parent_id` arrive as integers. The sibling
 * notifications/list endpoint uses a raw query and returns everything as
 * strings — see NotificationsController.
 */
class NotificationCategory extends Model
{
    protected $table = 'notification_categories';

    public $timestamps = false;

    protected $guarded = [];

    protected $casts = [
        'id' => 'integer',
        'parent_id' => 'integer',
    ];
}
