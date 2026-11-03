<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * The current shipped version of each mobile app.
 *
 * `version_number` is a varchar holding a dotted version such as 8.4.8, which
 * is why comparing it needs version_compare and not an operator — see
 * hasUpdateFrom.
 */
class AppVersion extends Model
{
    protected $table = 'app_versions';

    public $timestamps = false;

    protected $guarded = [];

    protected $casts = [
        'id' => 'integer',
        'status' => 'integer',
    ];

    public static function forOs(string $os): ?self
    {
        return static::where('os_type', strtolower(trim($os)))->first();
    }

    /**
     * Whether the caller's version is behind the shipped one.
     *
     * The old API wrote `$app->version_number > $version`. PHP compares two
     * dotted strings either numerically or lexicographically depending on
     * their shape, and both readings are wrong for versions:
     *
     *   "2.54" > "2.9"    numeric      false, but 2.54 is the newer release
     *   "8.4.10" > "8.4.9" lexical     false, but 8.4.10 is the newer release
     *
     * The first of those is live: iOS ships 2.54, so no client on 2.6 through
     * 2.9 has ever been told an update exists. version_compare understands
     * the segments.
     */
    public function hasUpdateFrom(string $clientVersion): bool
    {
        $clientVersion = trim($clientVersion);

        if ($clientVersion === '') {
            return false;
        }

        return version_compare((string) $this->version_number, $clientVersion, '>');
    }
}
