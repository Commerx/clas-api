<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * The existing `land` table — 16,979 rows, the busiest table in the system.
 */
class Land extends Model
{
    protected $table = 'land';

    protected $primaryKey = 'land_id';

    public $timestamps = false;

    protected $guarded = [];

    protected $casts = [
        'land_id' => 'integer',
        'owner' => 'integer',
    ];

    public function owner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'owner', 'id');
    }

    public function scopePubliclyVisible($query)
    {
        return $query->where('active', 'Y')->where('visibility_status', 'public');
    }
}
