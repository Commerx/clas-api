<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Which kinds of notification a user wants.
 *
 * Stored as 'Yes'/'No' enums because the Yii application reads the same rows
 * and compares against those strings. The API speaks booleans, so the casts
 * here are the translation and the only place it happens.
 *
 * Most accounts have no row at all — 11 of 5,600 do. The old API answered
 * such a caller with an error, which told the app nothing it could act on;
 * DEFAULTS is what everyone without a row effectively has, and is what the
 * API returns instead.
 */
class UserSetting extends Model
{
    protected $table = 'user_settings';

    public $timestamps = false;

    protected $guarded = [];

    /** Notification kinds, and what they are called in the response. */
    public const FLAGS = [
        'all_notifications',
        'wildlife_notifications',
        'awareness_notifications',
        'subscriber_notifications',
    ];

    public const DEFAULTS = [
        'all_notifications' => true,
        'wildlife_notifications' => true,
        'awareness_notifications' => true,
        'subscriber_notifications' => true,
    ];

    /** @return array<string,bool> */
    public function flags(): array
    {
        $flags = [];

        foreach (self::FLAGS as $flag) {
            $flags[$flag] = $this->{$flag} === 'Yes';
        }

        return $flags;
    }

    /** How a boolean from the API is written to an enum('Yes','No') column. */
    public static function toEnum(bool $value): string
    {
        return $value ? 'Yes' : 'No';
    }
}
