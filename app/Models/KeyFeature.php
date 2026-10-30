<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * The existing `key_features` table, used to expand a listing's
 * comma-separated key_features column into the `features` array.
 */
class KeyFeature extends Model
{
    protected $table = 'key_features';

    public $timestamps = false;

    protected $guarded = [];

    protected $casts = [
        'id' => 'integer',
        'sort_order' => 'integer',
    ];

    /** Active features in display order, as v7 loaded them. */
    public static function activeOrdered()
    {
        return static::query()
            ->where('status', 'active')
            ->orderBy('sort_order')
            ->get();
    }
}
