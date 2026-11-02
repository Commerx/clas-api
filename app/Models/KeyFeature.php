<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * The existing `key_features` table.
 *
 * Note the column names: the key is `feature_id` and the label is `feature`,
 * not id/name. Features form a two-level tree via `parent_id`.
 */
class KeyFeature extends Model
{
    protected $table = 'key_features';

    protected $primaryKey = 'feature_id';

    public $timestamps = false;

    protected $guarded = [];

    protected $casts = [
        'feature_id' => 'integer',
        'parent_id' => 'integer',
        'sort_order' => 'integer',
    ];

    /** Active features in display order, keyed by id for lookup. */
    public static function activeOrdered()
    {
        return static::query()
            ->where('status', 'active')
            ->orderBy('sort_order')
            ->get();
    }
}
