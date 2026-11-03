<?php

namespace App\Services;

use App\Mail\ConfirmEmail;
use App\Models\Profile;
use App\Models\Token;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;

/**
 * Creating an account.
 *
 * This replaces a chain that was hard to follow and easy to get wrong:
 * dektrium's RegistrationForm called User::register(), which saved the row and
 * then let the application's User::afterSave() reach back into
 * Yii::$app->request->post() to populate the profile. A model reading the HTTP
 * request is why the profile columns a signup actually writes are not visible
 * from either the form or the controller.
 *
 * Here the controller validates, this service writes, and both rows are
 * created in one transaction — the old path could leave a user with no profile
 * row if the second save failed, and several endpoints assume the profile
 * exists.
 *
 * What is deliberately unchanged, because the Yii application reads the same
 * rows:
 *
 *  - `role` is set to LANDOWNER. The old signup derived this from the Yii
 *    action name and every API registration came out LANDOWNER, which is why
 *    5,551 of 5,611 accounts have it. Nothing in the API reads the column; the
 *    web application might, so this keeps the existing value rather than
 *    guessing a better one.
 *  - An account works for config('clas.guest_days') days before it is
 *    confirmed, via allow_guest and guest_expiry_at.
 *  - The confirmation link points at the Yii application, which owns the page
 *    that consumes it.
 */
class Registration
{
    /**
     * Profile columns a signup may set.
     *
     * `guide` and `guide_number` are here because the old signup accepted
     * them and guides enter their number when registering. Worth knowing what
     * they do: BookingSubmission exempts a caller from availability checks
     * when their guide number matches one the listing owner has recorded
     * against that property. Self-declaring a number grants nothing on its
     * own — the owner must have listed it — but it does mean the exemption
     * rests on the number not being widely known.
     */
    private const PROFILE_FIELDS = [
        'first_name', 'last_name', 'display_name', 'guide', 'guide_number',
        'vehicle_description',
    ];

    /** @return array{0: User, 1: bool} the user, and whether mail was sent */
    public function register(array $input): array
    {
        $user = DB::transaction(function () use ($input) {
            $now = time();

            $user = User::create([
                'username' => trim($input['username']),
                'email' => trim($input['email']),
                'password_hash' => bcrypt($input['password']),
                // 32 characters, matching the column and what the Yii
                // application generates for the same purpose.
                'auth_key' => Str::random(32),
                'role' => 'LANDOWNER',
                'confirmed_at' => null,
                'allow_guest' => 1,
                'guest_expiry_at' => strtotime('+'.config('clas.guest_days').' days', $now),
                'registration_ip' => request()->ip(),
                'created_at' => $now,
                'updated_at' => $now,
                'flags' => 0,
            ]);

            $profile = ['user_id' => $user->id, 'gravatar_email' => $user->email];

            foreach (self::PROFILE_FIELDS as $field) {
                if (isset($input[$field]) && $input[$field] !== '') {
                    $profile[$field] = $input[$field];
                }
            }

            $first = trim((string) ($input['first_name'] ?? ''));
            $last = trim((string) ($input['last_name'] ?? ''));
            $full = trim($first.' '.$last);

            if ($full !== '') {
                $profile['name'] = $full;
                $profile['display_name'] ??= $full;
            }

            // The old code set guide from whether a number was present in
            // some paths and took it from the request in others. One rule:
            // a number means a guide.
            $profile['guide'] = ! empty($profile['guide_number']) ? 'Yes' : 'No';

            Profile::create($profile);

            return $user;
        });

        return [$user->fresh(), $this->sendConfirmation($user)];
    }

    /**
     * Issue a confirmation code and mail the link.
     *
     * Returns whether the message went out. The caller reports success either
     * way: the account exists, and a failed send is recoverable through
     * user/send-confirmation-code.
     */
    public function sendConfirmation(User $user): bool
    {
        $token = Token::issue((int) $user->id, Token::TYPE_CONFIRMATION);

        try {
            Mail::to($user->email)->send(new ConfirmEmail(
                name: (string) ($user->profile?->name ?: $user->username),
                url: $token->url(),
            ));
        } catch (\Throwable $e) {
            report($e);

            return false;
        }

        return true;
    }
}
