<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * The existing `profile` table. Keyed by user_id rather than its own id.
 */
class Profile extends Model
{
    protected $table = 'profile';

    protected $primaryKey = 'user_id';

    public $incrementing = false;

    public $timestamps = false;

    protected $guarded = [];

    protected $casts = [
        'user_id' => 'integer',
        'mute_user' => 'integer',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id', 'id');
    }

    /**
     * The v7 login response reports whether the address block has anything
     * in it. It counts street, city, province, country-or-postcode and phone,
     * and calls it present only when more than one is filled in.
     */
    public function addressPartsCount(): int
    {
        $parts = [];

        foreach (['street', 'city', 'province'] as $field) {
            if (! empty($this->{$field})) {
                $parts[] = $this->{$field};
            }
        }

        if (! empty($this->country) || ! empty($this->postal_code)) {
            $parts[] = trim($this->country.' '.$this->postal_code);
        }

        if (! empty($this->phone)) {
            $parts[] = $this->phone;
        }

        return count($parts);
    }

    /**
     * Same idea for the vehicle block, but v7 treats a single filled field as
     * present here, unlike the address block above. Kept as-is for parity.
     */
    public function vehiclePartsCount(): int
    {
        $fields = [
            'vehicle_description', 'vehicle_year', 'vehicle_make',
            'vehicle_model', 'vehilcle_color', 'license_plate',
            'driver_license_number', 'other_features',
        ];

        $count = 0;

        foreach ($fields as $field) {
            if (($this->{$field} ?? '') !== '') {
                $count++;
            }
        }

        return $count;
    }
}
