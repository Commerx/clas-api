<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Support\Facades\DB;

/**
 * The existing `user` table, carried over from the Yii application.
 *
 * Three things differ from a stock Laravel model and are deliberate:
 * the table is singular, the password column is `password_hash`, and
 * created_at/updated_at are unix integers rather than datetimes — so
 * Eloquent's timestamp handling is switched off.
 */
class User extends Model
{
    protected $table = 'user';

    public $timestamps = false;

    protected $guarded = [];

    protected $hidden = ['password_hash', 'auth_key'];

    protected $casts = [
        'id' => 'integer',
        'allow_guest' => 'integer',
        'guest_expiry_at' => 'integer',
        'is_rid_user' => 'integer',
        'confirmed_at' => 'integer',
        'blocked_at' => 'integer',
        'created_at' => 'integer',
        'updated_at' => 'integer',
        'flags' => 'integer',
    ];

    public function profile(): HasOne
    {
        return $this->hasOne(Profile::class, 'user_id', 'id');
    }

    public function lands(): HasMany
    {
        return $this->hasMany(Land::class, 'owner', 'id');
    }

    /**
     * Mirrors dektrium's findUserByUsernameOrEmail: the mobile apps send one
     * `login` field and the user may type either.
     */
    public static function findByLogin(string $login): ?self
    {
        return static::where('username', $login)
            ->orWhere('email', $login)
            ->first();
    }

    /**
     * bcrypt, cost 10, written by Yii's security component. Verified
     * interoperable with PHP 8.5's password_verify, so existing hashes
     * authenticate unchanged and no password reset is required.
     */
    public function validatePassword(string $password): bool
    {
        return password_verify($password, (string) $this->password_hash);
    }

    public function isConfirmed(): bool
    {
        return $this->confirmed_at !== null;
    }

    public function isBlocked(): bool
    {
        return $this->blocked_at !== null;
    }

    public function allowsGuest(): bool
    {
        return (int) $this->allow_guest === 1;
    }

    /**
     * Listing access subscription. Unlike the three below, the old API does not filter
     * this one on expiration_date — only on status — so neither do we.
     */
    public function lastListingSubscription(): ?object
    {
        return DB::table('user_subscription')
            ->where('user_id', $this->id)
            ->where('status', 'Active')
            ->orderByDesc('subscription_id')
            ->first();
    }

    public function wmuSubscription(): ?object
    {
        return $this->activeSubscriptionFrom('wmu_subscription');
    }

    public function darkModeSubscription(): ?object
    {
        // note: table is darkmode_subscription, not dark_mode_subscription,
        // while the response key below uses dark_mode_subscription
        return $this->activeSubscriptionFrom('darkmode_subscription');
    }

    public function fishingLandsSubscription(): ?object
    {
        return $this->activeSubscriptionFrom('fishing_lands_subscription');
    }

    private function activeSubscriptionFrom(string $table): ?object
    {
        return DB::table($table)
            ->where('user_id', $this->id)
            ->where('status', 'Active')
            ->whereRaw('expiration_date >= CURDATE()')
            ->orderByDesc('id')
            ->first();
    }
}
