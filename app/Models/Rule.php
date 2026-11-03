<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** Access rules a listing can carry, shown to the app as a reference list. */
class Rule extends Model
{
    protected $table = 'rules';

    protected $primaryKey = 'rule_id';

    public $timestamps = false;

    protected $guarded = [];

    protected $casts = ['rule_id' => 'integer'];

    /** @return list<array{rule_id:int,rule:string}> */
    public static function all_(): array
    {
        return static::orderBy('rule_id')
            ->get(['rule_id', 'rule'])
            ->map(fn ($r) => ['rule_id' => (int) $r->rule_id, 'rule' => (string) $r->rule])
            ->all();
    }
}
