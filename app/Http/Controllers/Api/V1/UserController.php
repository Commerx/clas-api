<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\ApiResponse;
use App\Http\Controllers\Controller;
use App\Mail\IssueReported;
use App\Models\Profile;
use App\Models\User;
use App\Services\Payments\PaymentGateway;
use App\Services\Payments\PaymentGatewayException;
use App\Services\Registration;
use App\Services\SubscriptionPurchase;
use App\Support\V1\AccountPayload;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * Rebuild of modules/api/controllers/v7/UserController.php.
 *
 * Registration, the account the app shows on its profile screen, and the
 * writes to it.
 *
 * The three update actions are the reason this group was worth rebuilding
 * carefully. In the old API each of them read like this:
 *
 *     $user = User::findOne(['auth_key' => $auth_key, 'id' => $uid]);
 *     $profile = Profile::findOne(['user_id' => $uid]);
 *     $profile->load($postedData);
 *     $profile->save();
 *
 * `$user` is assigned and never read. The controller has no access filter, so
 * nothing else checks the token either: the profile that gets written is
 * whichever one `uid` names, and the token is never required to match it or
 * to be valid at all. Here every write goes to the caller resolved from the
 * token by the auth.token middleware, `uid` is a cross-check and never a
 * lookup, and the columns a request may touch are listed rather than taken
 * from whatever was posted.
 *
 * user/subscribe and user/apply-promocode are not here: both run card
 * payments through Moneris and belong with the rest of payments, after the
 * Stripe decision.
 */
class UserController extends Controller
{
    /**
     * The reply to every send-confirmation-code call that is not a signed-in
     * caller asking about their own confirmed account.
     */
    private const SENT_MESSAGE = 'If that account needs confirming, a link is on its way. '
        .'It contains a confirmation link that you must click to complete registration.';

    /** Columns update-address may write. */
    private const ADDRESS_FIELDS = [
        'first_name', 'last_name', 'street', 'city', 'province', 'country',
        'postal_code', 'phone',
    ];

    /** Columns update-vehicle-info may write. */
    private const VEHICLE_FIELDS = [
        'vehicle_description', 'vehicle_year', 'vehicle_make', 'vehicle_model',
        'vehilcle_color', 'license_plate', 'driver_license_number',
        'other_features',
    ];

    /** Columns update-profile may write: both of the above, plus the bio. */
    private const PROFILE_FIELDS = [
        'display_name', 'brief_bio', 'occupation', 'guide_number',
    ];

    public function signup(Request $request, Registration $registration): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            // dektrium's rules, kept so the two applications accept the same
            // usernames: a letter, then word characters.
            'username' => [
                'required', 'string', 'min:3', 'max:20',
                'regex:/^[a-zA-Z]\w+$/', 'unique:user,username',
            ],
            'email' => ['required', 'email', 'max:255', 'unique:user,email'],
            'password' => ['required', 'string', 'min:6'],
            'first_name' => ['required', 'string', 'max:60'],
            'last_name' => ['required', 'string', 'max:60'],
            'terms_of_use' => ['required', 'accepted'],
            'guide_number' => ['nullable', 'string', 'max:50'],
            'display_name' => ['nullable', 'string', 'max:100'],
            'vehicle_description' => ['nullable', 'string'],
        ], [
            'terms_of_use.accepted' => 'You must agree to the Terms of Use before sign up.',
            'username.regex' => 'A username must start with a letter and contain only letters, numbers and underscores.',
        ]);

        if ($validator->fails()) {
            return ApiResponse::invalid($validator->errors()->toArray());
        }

        [$user, $mailed] = $registration->register($validator->validated());

        return ApiResponse::data(
            AccountPayload::identity($user) + ['confirmation_sent' => $mailed],
            201,
        );
    }

    /** The caller's own account and profile. */
    public function get(Request $request): JsonResponse
    {
        return ApiResponse::data(AccountPayload::withProfile($this->caller($request)));
    }

    public function updateAddress(Request $request): JsonResponse
    {
        return $this->writeProfile($request, self::ADDRESS_FIELDS, [
            'first_name' => ['required', 'string', 'max:60'],
            'last_name' => ['required', 'string', 'max:60'],
            'street' => ['required', 'string', 'max:100'],
            'city' => ['required', 'string', 'max:60'],
            'province' => ['required', 'string', 'max:60'],
            'country' => ['required', 'string', 'max:30'],
            'postal_code' => ['required', 'string', 'max:30'],
            'phone' => ['required', 'string', 'min:10', 'max:15'],
        ]);
    }

    public function updateVehicleInfo(Request $request): JsonResponse
    {
        return $this->writeProfile($request, self::VEHICLE_FIELDS, [
            'driver_license_number' => ['required', 'string', 'max:60'],
        ] + $this->vehicleRules());
    }

    public function updateProfile(Request $request): JsonResponse
    {
        return $this->writeProfile(
            $request,
            array_merge(self::ADDRESS_FIELDS, self::VEHICLE_FIELDS, self::PROFILE_FIELDS),
            [
                'first_name' => ['required', 'string', 'max:60'],
                'last_name' => ['required', 'string', 'max:60'],
                'street' => ['required', 'string', 'max:100'],
                'city' => ['required', 'string', 'max:60'],
                'province' => ['required', 'string', 'max:60'],
                'country' => ['required', 'string', 'max:30'],
                'postal_code' => ['required', 'string', 'max:30'],
                'phone' => ['required', 'string', 'min:10', 'max:15'],
                'driver_license_number' => ['required', 'string', 'max:60'],
                'display_name' => ['nullable', 'string', 'max:100'],
                'brief_bio' => ['nullable', 'string'],
                'occupation' => ['nullable', 'string', 'max:255'],
                'guide_number' => ['nullable', 'string', 'max:50'],
            ] + $this->vehicleRules(),
        );
    }

    /**
     * Change the password.
     *
     * This rotates `auth_key`, which the old endpoint did not: a password
     * changed because it might have leaked left every token issued under the
     * old one working indefinitely. Rotating signs the account out everywhere,
     * which is the expected result of changing a password, so the new token is
     * returned here for the caller to keep using.
     */
    public function account(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'current_password' => ['required', 'string'],
            'new_password' => ['required', 'string', 'min:6'],
        ]);

        if ($validator->fails()) {
            return ApiResponse::invalid($validator->errors()->toArray());
        }

        $user = $this->caller($request);

        if (! $user->validatePassword((string) $request->input('current_password'))) {
            return ApiResponse::invalid([
                'current_password' => ['That is not your current password.'],
            ]);
        }

        $user->password_hash = Hash::make((string) $request->input('new_password'));
        $user->auth_key = Str::random(32);
        $user->updated_at = time();
        $user->save();

        return ApiResponse::data([
            'token' => $user->auth_key,
            'message' => 'Your password has been changed. Other devices will need to sign in again.',
        ]);
    }

    /**
     * Close the account.
     *
     * Blocks it and rotates the token, so every signed-in device stops
     * working immediately. Nothing is deleted: the user's requests and
     * listings are referenced by other people's records.
     */
    public function deactivate(Request $request): JsonResponse
    {
        $user = $this->caller($request);

        // Carried over: a user part-way through a six-month subscription
        // cannot close the account themselves. subscription_type 2 was the
        // six-month plan; it is no longer sold but existing rows remain.
        $subscription = $user->lastListingSubscription();

        if ($subscription !== null && (int) $subscription->subscription_type === 2) {
            return ApiResponse::error(
                "You can't deactivate your account since you are subscribed for 6 months access!",
                409,
            );
        }

        $user->blocked_at = time();
        $user->auth_key = Str::random(32);
        $user->updated_at = time();
        $user->save();

        return ApiResponse::data(['deactivated' => true]);
    }

    /** Send the user's report to the admin address. */
    public function reportIssue(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'subject' => ['required', 'string', 'max:255'],
            'issue_type' => ['required', 'string', 'max:100'],
            'message' => ['required', 'string', 'max:5000'],
        ]);

        if ($validator->fails()) {
            return ApiResponse::invalid($validator->errors()->toArray());
        }

        $user = $this->caller($request);

        $mail = Mail::to(config('clas.admin_email'));

        if ($copies = config('clas.issue_notify_emails')) {
            $mail->bcc($copies);
        }

        $mail->send(new IssueReported(
            reporterName: (string) ($user->profile?->name ?: $user->username),
            reporterEmail: (string) $user->email,
            issueSubject: (string) $request->input('subject'),
            issueType: (string) $request->input('issue_type'),
            body: (string) $request->input('message'),
        ));

        return ApiResponse::data(['reported' => true]);
    }

    /**
     * Resend the email confirmation link.
     *
     * Two callers, and they get different treatment on purpose. A signed-in
     * caller is asking about their own account, so the answer can be specific.
     * A caller supplying only an email address is unauthenticated — the app
     * needs this on the sign-in screen — and gets the same reply whatever the
     * address turns out to be. The old endpoint answered "User does not
     * exists!", "This account has already been confirmed!" or success, which
     * between them reported whether any given address had an account and
     * whether it was active. It was also a GET, so those answers were
     * reachable by following a link.
     */
    public function sendConfirmationCode(Request $request, Registration $registration): JsonResponse
    {
        $caller = $request->attributes->get('api_user');

        if ($caller instanceof User) {
            if ($caller->isConfirmed()) {
                return ApiResponse::error('This account has already been confirmed.', 409);
            }

            $registration->sendConfirmation($caller);

            return ApiResponse::data(['message' => self::SENT_MESSAGE]);
        }

        $validator = Validator::make($request->all(), [
            'email' => ['required', 'email'],
        ]);

        if ($validator->fails()) {
            return ApiResponse::invalid($validator->errors()->toArray());
        }

        $user = User::where('email', trim((string) $request->input('email')))->first();

        if ($user !== null && ! $user->isConfirmed() && ! $user->isBlocked()) {
            $registration->sendConfirmation($user);
        }

        return ApiResponse::data(['message' => self::SENT_MESSAGE]);
    }

    /**
     * Apply a write to the caller's own profile.
     *
     * `$fields` is what this endpoint is allowed to change; anything else in
     * the request is ignored rather than rejected, because the apps post the
     * whole profile screen at once. A missing profile row is created, since
     * several endpoints assume one exists and a few old accounts have none.
     */
    private function writeProfile(Request $request, array $fields, array $rules): JsonResponse
    {
        $validator = Validator::make($request->all(), $rules);

        if ($validator->fails()) {
            return ApiResponse::invalid($validator->errors()->toArray());
        }

        $userId = $this->userId($request);
        $profile = Profile::firstOrNew(['user_id' => $userId]);

        foreach ($fields as $field) {
            if ($request->has($field)) {
                $profile->{$field} = $request->input($field);
            }
        }

        $first = trim((string) $profile->first_name);
        $last = trim((string) $profile->last_name);
        $full = trim($first.' '.$last);

        if ($full !== '') {
            $profile->name = $full;
        }

        // A recorded guide number is what makes someone a guide, as before.
        // Recomputed from the stored value rather than the request, so an
        // endpoint that cannot write the number cannot change the flag.
        $profile->guide = ! empty($profile->guide_number) ? 'Yes' : 'No';

        $profile->save();

        return ApiResponse::data(AccountPayload::withProfile($this->caller($request)->fresh()));
    }

    /**
     * What a subscription costs, with a promo code applied.
     *
     * A read: no code is consumed and nothing is charged, so the app can
     * show a total before the customer commits. The old endpoint behaved the
     * same way, but its discount calculation gave 100% off for any code
     * present in `promo_codes` regardless of the code's type — see
     * App\Services\Payments\Promocodes.
     */
    public function applyPromocode(Request $request, SubscriptionPurchase $purchase): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'promo_code' => ['required', 'string', 'max:32'],
            'subscription_type' => ['required', 'integer'],
        ]);

        if ($validator->fails()) {
            return ApiResponse::invalid($validator->errors()->toArray());
        }

        $type = (int) $request->input('subscription_type');

        if (! array_key_exists($type, (array) config('clas.subscriptions.types'))) {
            return ApiResponse::invalid([
                'subscription_type' => ['That subscription type does not exist.'],
            ]);
        }

        $discount = $purchase->quote($type, (string) $request->input('promo_code'));

        if (! $discount->wasApplied()) {
            return ApiResponse::error('That promo code is not valid.', 422);
        }

        return ApiResponse::data($discount->toArray());
    }

    /**
     * Buy a property-access subscription.
     *
     * Takes a Stripe payment method id created on the device, never a card
     * number — the old endpoint took `cc_number`, `cc_expiry` and `cc_cvv` as
     * POST parameters, which is what puts that application in PCI DSS SAQ D
     * scope. `payment_method_id` is only required when there is something to
     * pay: a code worth 100% needs no card.
     *
     * The subscription is written with `auto_renew = 0` and
     * `gateway = 'stripe'`, which is what keeps the old Yii renewal cron off
     * it; docs/moneris-to-stripe-migration.md explains why that matters.
     */
    public function subscribe(Request $request, SubscriptionPurchase $purchase): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'subscription_type' => ['required', 'integer'],
            'payment_method_id' => ['nullable', 'string', 'max:255'],
            'promo_code' => ['nullable', 'string', 'max:32'],
        ]);

        if ($validator->fails()) {
            return ApiResponse::invalid($validator->errors()->toArray());
        }

        if (! app(PaymentGateway::class)->isConfigured()) {
            // No usable key, or a key this deployment refuses — see
            // config/services.php. Saying so beats a gateway error.
            return ApiResponse::error('Payments are not available on this server.', 503);
        }

        $type = (int) $request->input('subscription_type');

        if (! array_key_exists($type, (array) config('clas.subscriptions.types'))) {
            return ApiResponse::invalid([
                'subscription_type' => ['That subscription type does not exist.'],
            ]);
        }

        if (! config('clas.subscriptions.types.'.$type.'.sold')) {
            return ApiResponse::error('That subscription is no longer offered.', 409);
        }

        try {
            $result = $purchase->buy(
                user: $this->caller($request),
                type: $type,
                code: (string) $request->input('promo_code', ''),
                paymentMethodId: (string) $request->input('payment_method_id', ''),
            );
        } catch (PaymentGatewayException $e) {
            return ApiResponse::error($e->getMessage(), 422);
        } catch (RuntimeException $e) {
            return ApiResponse::error($e->getMessage(), 409);
        }

        return ApiResponse::data([
            'subscription_id' => $result['subscription_id'],
            'amount_paid' => $result['discount']->total,
            'discount' => $result['discount']->wasApplied()
                ? $result['discount']->toArray()
                : null,
            'reference' => $result['payment']?->reference,
            'account' => AccountPayload::withProfile($this->caller($request)->fresh()),
        ], 201);
    }

    /** @return array<string,list<string>> */
    private function vehicleRules(): array
    {
        return [
            'vehicle_description' => ['nullable', 'string'],
            'vehicle_year' => ['nullable', 'string', 'max:30'],
            'vehicle_make' => ['nullable', 'string', 'max:60'],
            'vehicle_model' => ['nullable', 'string', 'max:60'],
            'vehilcle_color' => ['nullable', 'string', 'max:60'],
            'license_plate' => ['nullable', 'string', 'max:60'],
            'other_features' => ['nullable', 'string', 'max:255'],
        ];
    }
}
