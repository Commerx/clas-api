<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\ApiResponse;
use App\Http\Controllers\Controller;
use App\Models\UserSetting;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

/**
 * Rebuild of modules/api/controllers/v7/UserSettingsController.php.
 *
 * Which kinds of notification the caller wants. Four switches, stored in
 * `user_settings` as 'Yes'/'No' because the Yii application reads the same
 * rows; the API speaks booleans and App\Models\UserSetting translates.
 *
 * Only 11 accounts of 5,600 have a row. The old read endpoint answered
 * everyone else with `success: false, error: "No settings exist for this
 * user!"` — an error for the ordinary case, and one the app could do nothing
 * with except fall back to assumed defaults. This returns those defaults, so
 * the response says what is actually in effect, and `configured` says whether
 * the user has ever chosen.
 */
class UserSettingsController extends Controller
{
    public function get(Request $request): JsonResponse
    {
        $settings = UserSetting::where('user_id', $this->userId($request))->first();

        return ApiResponse::data([
            'configured' => $settings !== null,
        ] + ($settings?->flags() ?? UserSetting::DEFAULTS));
    }

    public function save(Request $request): JsonResponse
    {
        $rules = [];

        foreach (UserSetting::FLAGS as $flag) {
            // The old endpoint defaulted every missing switch to 'Yes', so a
            // request that mentioned one switch silently turned the other
            // three back on. Each is now optional and independent: only what
            // the request names is changed.
            $rules[$flag] = ['sometimes', 'boolean'];
        }

        $validator = Validator::make($request->all(), $rules);

        if ($validator->fails()) {
            return ApiResponse::invalid($validator->errors()->toArray());
        }

        $userId = $this->userId($request);
        $settings = UserSetting::firstOrNew(['user_id' => $userId]);

        // A new row starts from the defaults rather than from empty columns,
        // which the enum would reject.
        foreach (UserSetting::DEFAULTS as $flag => $default) {
            $current = $settings->exists ? $settings->{$flag} === 'Yes' : $default;

            $settings->{$flag} = UserSetting::toEnum(
                $request->has($flag) ? $request->boolean($flag) : $current,
            );
        }

        $now = date('Y-m-d H:i:s');

        if (! $settings->exists) {
            $settings->created = $now;
        }

        $settings->updated = $now;
        $settings->user_id = $userId;
        $settings->save();

        return ApiResponse::data(['configured' => true] + $settings->flags());
    }
}
