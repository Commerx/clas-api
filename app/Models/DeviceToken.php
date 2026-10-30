<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * The existing `device_tokens` table — 3,717 rows across 3,551 users.
 * One row per user per OS; login replaces rather than updates.
 */
class DeviceToken extends Model
{
    protected $table = 'device_tokens';

    protected $primaryKey = 'device_token_id';

    public $timestamps = false;

    protected $guarded = [];

    protected $casts = [
        'device_token_id' => 'integer',
        'user_id' => 'integer',
    ];
}
