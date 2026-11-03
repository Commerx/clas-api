<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

/**
 * Email confirmation and password reset codes.
 *
 * This is dektrium's `token` table, and the Yii application still serves the
 * pages that consume these codes. So the row this writes must be exactly what
 * that application expects to read: `code` is a 32-character string, the
 * `type` constants are its numbering, and `created_at` is a unix integer.
 *
 * The table has no id and no unique key — dektrium finds a token by
 * (user_id, code, type) — so issuing a new code does not invalidate the
 * previous one. Both applications therefore delete the user's existing codes
 * of that type before inserting, which is what makes a reset link single-use
 * in practice.
 */
class Token extends Model
{
    protected $table = 'token';

    public $timestamps = false;

    /** The table has no single primary key column. */
    protected $primaryKey = null;

    public $incrementing = false;

    protected $guarded = [];

    protected $casts = [
        'user_id' => 'integer',
        'type' => 'integer',
        'created_at' => 'integer',
    ];

    public const TYPE_CONFIRMATION = 0;

    public const TYPE_RECOVERY = 1;

    public const TYPE_CONFIRM_NEW_EMAIL = 2;

    public const TYPE_CONFIRM_OLD_EMAIL = 3;

    /**
     * Issue a code, replacing any the user already holds of this type.
     *
     * Replacing rather than adding matters: without it every reset email ever
     * sent to an address stays usable until it expires, so one leaked message
     * from weeks ago is still a working password reset.
     */
    public static function issue(int $userId, int $type): self
    {
        static::where('user_id', $userId)->where('type', $type)->delete();

        $token = new self([
            'user_id' => $userId,
            'type' => $type,
            // 32 characters, matching the column width and dektrium's own
            // Yii::$app->security->generateRandomString().
            'code' => Str::random(32),
            'created_at' => time(),
        ]);

        $token->save();

        return $token;
    }

    /** The page on the Yii application that consumes this code. */
    public function url(): string
    {
        $route = match ($this->type) {
            self::TYPE_CONFIRMATION => '/user/registration/confirm',
            self::TYPE_RECOVERY => '/user/recovery/reset',
            self::TYPE_CONFIRM_NEW_EMAIL, self::TYPE_CONFIRM_OLD_EMAIL => '/user/settings/confirm',
        };

        return rtrim((string) config('clas.legacy_web_url'), '/')
            .$route
            .'?'.http_build_query(['id' => $this->user_id, 'code' => $this->code]);
    }
}
