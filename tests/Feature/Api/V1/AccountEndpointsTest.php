<?php

namespace Tests\Feature\Api\V1;

use App\Mail\ConfirmEmail;
use App\Mail\IssueReported;
use App\Mail\ResetPassword;
use App\Models\AppVersion;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

/**
 * The user, settings and security endpoints.
 *
 * Mail is faked throughout, so nothing here sends a message. The fixture
 * account is restored in tearDown because several of these change it.
 */
class AccountEndpointsTest extends TestCase
{
    private object $user;

    private array $userBefore;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'database.default' => 'mysql',
            'database.connections.mysql.database' => env('CONTRACT_DB', 'clas'),
        ]);

        try {
            $user = DB::table('user')->where('username', 'contract_fixture')->first();
        } catch (\Throwable $e) {
            $this->markTestSkipped('clas database not reachable: '.$e->getMessage());
        }

        if ($user === null) {
            $this->markTestSkipped('Fixture account missing; see tests/Fixtures/v1/README.md.');
        }

        $this->user = $user;
        $this->userBefore = (array) $user;

        Mail::fake();
    }

    protected function tearDown(): void
    {
        if (isset($this->userBefore)) {
            DB::table('user')->where('id', $this->user->id)->update($this->userBefore);
            DB::table('token')->where('user_id', $this->user->id)->delete();
        }

        parent::tearDown();
    }

    private function token(): string
    {
        return $this->user->auth_key;
    }

    // ---- version-check -------------------------------------------------

    /**
     * The bug this replaces, stated as the two comparisons that got it wrong.
     *
     * iOS ships 2.54. Under the old string comparison no client on 2.6
     * through 2.9 was ever told an update existed, because PHP read both as
     * numbers and 2.54 is less than 2.9.
     */
    public function test_a_dotted_version_is_compared_as_a_version_not_a_string(): void
    {
        $app = new AppVersion(['version_number' => '2.54']);

        $this->assertTrue($app->hasUpdateFrom('2.9'),
            '2.54 is a later release than 2.9, whatever a numeric comparison says.');
        $this->assertTrue($app->hasUpdateFrom('2.53'));
        $this->assertFalse($app->hasUpdateFrom('2.54'));
        $this->assertFalse($app->hasUpdateFrom('2.60'));

        // And the lexicographic reading, which bites three-part versions.
        $app = new AppVersion(['version_number' => '8.4.10']);

        $this->assertTrue($app->hasUpdateFrom('8.4.9'),
            '8.4.10 is a later release than 8.4.9.');
        $this->assertFalse($app->hasUpdateFrom('8.4.10'));
    }

    public function test_version_check_is_open_and_returns_reference_data(): void
    {
        $row = DB::table('app_versions')->where('status', 1)->first();

        if ($row === null) {
            $this->markTestSkipped('No active app_versions row.');
        }

        $response = $this->getJson('/v1/security/version-check?os='.$row->os_type.'&version=0.1')
            ->assertOk();

        $data = $response->json('data');

        $this->assertTrue($data['hasUpdate'], 'Version 0.1 is behind every shipped build.');

        // Both keys are present whatever the answer; the old API returned
        // `message` only on update and `alert` only when there was none.
        $this->assertArrayHasKey('message', $data);
        $this->assertArrayHasKey('alert', $data);
        $this->assertNull($data['alert']);

        // Reference lists are arrays, not objects keyed by their own values.
        $this->assertArrayHasKey(0, $data['booking_reasons']);
        $this->assertArrayHasKey(0, $data['signage_options']);
        $this->assertSame(1, $data['signage_options'][0]['id']);
    }

    public function test_version_check_rejects_an_unknown_platform(): void
    {
        $this->getJson('/v1/security/version-check?os=symbian&version=1.0')
            ->assertStatus(404);
    }

    // ---- forgot-password -----------------------------------------------

    /**
     * The reply must not depend on whether the address exists, or the
     * endpoint is a way to test whether someone has an account here.
     */
    public function test_forgot_password_answers_identically_for_unknown_addresses(): void
    {
        $known = $this->postJson('/v1/security/forgot-password', ['email' => $this->user->email])
            ->assertOk();

        $unknown = $this->postJson('/v1/security/forgot-password', [
            'email' => 'nobody-'.uniqid().'@example.com',
        ])->assertOk();

        $this->assertSame($known->json(), $unknown->json(),
            'The response reveals whether the address has an account.');

        // The difference is only in what was sent.
        Mail::assertSent(ResetPassword::class, 1);
    }

    public function test_forgot_password_issues_a_single_usable_code(): void
    {
        $this->postJson('/v1/security/forgot-password', ['email' => $this->user->email])
            ->assertOk();
        $this->postJson('/v1/security/forgot-password', ['email' => $this->user->email])
            ->assertOk();

        // Asking twice must not leave two working links, or every reset mail
        // ever sent to the address stays live until it expires.
        $this->assertSame(1, DB::table('token')
            ->where('user_id', $this->user->id)
            ->where('type', 1)
            ->count());
    }

    // ---- send-confirmation-code ----------------------------------------

    public function test_send_confirmation_code_is_uniform_for_anonymous_callers(): void
    {
        $known = $this->postJson('/v1/user/send-confirmation-code', [
            'email' => $this->user->email,
        ])->assertOk();

        $unknown = $this->postJson('/v1/user/send-confirmation-code', [
            'email' => 'nobody-'.uniqid().'@example.com',
        ])->assertOk();

        $this->assertSame($known->json(), $unknown->json(),
            'The response reveals whether the address has an account.');
    }

    public function test_a_signed_in_caller_is_told_their_account_is_already_confirmed(): void
    {
        if (! $this->user->confirmed_at) {
            $this->markTestSkipped('The fixture account is not confirmed.');
        }

        $this->postJson('/v1/user/send-confirmation-code', ['token' => $this->token()])
            ->assertStatus(409);

        Mail::assertNothingSent();
    }

    // ---- user/get ------------------------------------------------------

    public function test_get_returns_the_profile_without_internal_columns(): void
    {
        $data = $this->getJson('/v1/user/get?token='.$this->token())
            ->assertOk()
            ->json('data');

        $this->assertSame((int) $this->user->id, $data['id']);
        $this->assertArrayHasKey('street', $data);
        $this->assertArrayHasKey('guide', $data);

        foreach (['pipedrive_person_id', 'gravatar_id', 'gravatar_email', 'question', 'mute_user'] as $withheld) {
            $this->assertArrayNotHasKey($withheld, $data,
                $withheld.' is internal and must not be in the response.');
        }

        // mute_user is reported the way round it reads.
        $this->assertArrayHasKey('send_notifications', $data);
    }

    public function test_get_requires_a_token(): void
    {
        $this->getJson('/v1/user/get')->assertStatus(401);
    }

    // ---- password change -----------------------------------------------

    public function test_the_current_password_must_be_right(): void
    {
        DB::table('user')->where('id', $this->user->id)
            ->update(['password_hash' => bcrypt('known-password')]);

        $this->postJson('/v1/user/account', [
            'token' => $this->token(),
            'current_password' => 'definitely-not-it',
            'new_password' => 'newpassword123',
        ])->assertStatus(422);

        $this->assertSame($this->user->auth_key, DB::table('user')
            ->where('id', $this->user->id)
            ->value('auth_key'), 'A refused change must not rotate the token.');
    }

    /**
     * A successful change rotates the token, so other devices are signed out.
     *
     * The old endpoint left every previously issued token working, which
     * meant changing a password because it might have leaked did not end the
     * access the leak had given away.
     */
    public function test_changing_the_password_rotates_the_token(): void
    {
        // Give the fixture a password this test knows. tearDown puts the
        // whole row back, so this does not outlive the test -- and skipping
        // instead would leave the behaviour that matters most here uncovered.
        DB::table('user')->where('id', $this->user->id)
            ->update(['password_hash' => bcrypt('known-password')]);

        $old = $this->token();

        $response = $this->postJson('/v1/user/account', [
            'token' => $old,
            'current_password' => 'known-password',
            'new_password' => 'newpassword123',
        ]);

        $response->assertOk();
        $new = $response->json('data.token');

        $this->assertNotSame($old, $new, 'The token must change.');

        // The old token is dead, and the new one works.
        $this->getJson('/v1/user/get?token='.$old)->assertStatus(401);
        $this->getJson('/v1/user/get?token='.$new)->assertOk();
    }

    // ---- report-issue --------------------------------------------------

    /**
     * The mail goes out from this application, with the reporter reachable
     * through Reply-To. Sending as the user fails SPF and DKIM for any
     * domain they do not control.
     */
    public function test_a_reported_issue_is_sent_from_the_application_not_the_user(): void
    {
        $this->postJson('/v1/user/report-issue', [
            'token' => $this->token(),
            'subject' => 'Cannot check in',
            'issue_type' => 'Bug',
            'message' => "Line one\nLine two",
        ])->assertOk();

        Mail::assertSent(IssueReported::class, function ($mail) {
            $envelope = $mail->envelope();

            $this->assertSame(
                config('notifications.from.address'),
                $envelope->from->address,
                'From must be this application, not the reporting user.',
            );

            $this->assertSame(
                $this->user->email,
                $envelope->replyTo[0]->address,
                'The reporter belongs in Reply-To.',
            );

            return true;
        });
    }

    public function test_report_issue_requires_a_token(): void
    {
        $this->postJson('/v1/user/report-issue', [
            'subject' => 'x', 'issue_type' => 'y', 'message' => 'z',
        ])->assertStatus(401);
    }

    // ---- notification settings -----------------------------------------

    public function test_settings_return_defaults_when_the_user_has_never_chosen(): void
    {
        $existing = DB::table('user_settings')->where('user_id', $this->user->id)->first();
        DB::table('user_settings')->where('user_id', $this->user->id)->delete();

        $data = $this->getJson('/v1/user-settings/get-notification-settings?token='.$this->token())
            ->assertOk()
            ->json('data');

        $this->assertFalse($data['configured'], 'Nothing has been chosen yet.');
        $this->assertTrue($data['all_notifications']);
        $this->assertTrue($data['subscriber_notifications']);

        if ($existing !== null) {
            DB::table('user_settings')->insert((array) $existing);
        }
    }

    /**
     * Each switch is independent.
     *
     * The old endpoint defaulted every absent switch to 'Yes', so turning one
     * off through a client that sent only that field turned the other three
     * back on.
     */
    public function test_changing_one_switch_leaves_the_others_alone(): void
    {
        $existing = DB::table('user_settings')->where('user_id', $this->user->id)->first();

        $this->postJson('/v1/user-settings/save-notification-settings', [
            'token' => $this->token(),
            'all_notifications' => false,
            'wildlife_notifications' => false,
            'awareness_notifications' => true,
            'subscriber_notifications' => true,
        ])->assertOk();

        $data = $this->postJson('/v1/user-settings/save-notification-settings', [
            'token' => $this->token(),
            'awareness_notifications' => false,
        ])->assertOk()->json('data');

        $this->assertFalse($data['awareness_notifications'], 'The named switch changed.');
        $this->assertFalse($data['all_notifications'], 'An unnamed switch must keep its value.');
        $this->assertFalse($data['wildlife_notifications'], 'An unnamed switch must keep its value.');
        $this->assertTrue($data['subscriber_notifications'], 'An unnamed switch must keep its value.');

        DB::table('user_settings')->where('user_id', $this->user->id)->delete();

        if ($existing !== null) {
            DB::table('user_settings')->insert((array) $existing);
        }
    }

    // ---- signup --------------------------------------------------------

    public function test_signup_creates_the_user_and_profile_together(): void
    {
        $username = 'test'.substr(uniqid(), -8);
        $email = $username.'@example.com';

        $data = $this->postJson('/v1/user/signup', [
            'username' => $username,
            'email' => $email,
            'password' => 'signup-password',
            'first_name' => 'Ada',
            'last_name' => 'Lovelace',
            'terms_of_use' => 1,
        ])->assertStatus(201)->json('data');

        try {
            $this->assertSame('Ada Lovelace', $data['name'],
                'The name the signup collected must reach the profile.');
            $this->assertFalse($data['confirmed']);
            $this->assertNotEmpty($data['token']);

            $profile = DB::table('profile')->where('user_id', $data['id'])->first();
            $this->assertNotNull($profile, 'A user without a profile row breaks other endpoints.');
            $this->assertSame('Ada', $profile->first_name);
            $this->assertSame('No', $profile->guide, 'No guide number means not a guide.');

            Mail::assertSent(ConfirmEmail::class);

            // A confirmation code is waiting for the link in that mail.
            $this->assertSame(1, DB::table('token')
                ->where('user_id', $data['id'])->where('type', 0)->count());
        } finally {
            DB::table('token')->where('user_id', $data['id'])->delete();
            DB::table('profile')->where('user_id', $data['id'])->delete();
            DB::table('user')->where('id', $data['id'])->delete();
        }
    }

    public function test_signup_refuses_a_duplicate_username_or_email(): void
    {
        $this->postJson('/v1/user/signup', [
            'username' => $this->user->username,
            'email' => 'fresh-'.uniqid().'@example.com',
            'password' => 'signup-password',
            'first_name' => 'Ada', 'last_name' => 'Lovelace', 'terms_of_use' => 1,
        ])->assertStatus(422)
            ->assertJsonPath('error', 'The username has already been taken.')
            ->assertJsonPath('errors.username.0', 'The username has already been taken.');

        $this->postJson('/v1/user/signup', [
            'username' => 'fresh'.substr(uniqid(), -6),
            'email' => $this->user->email,
            'password' => 'signup-password',
            'first_name' => 'Ada', 'last_name' => 'Lovelace', 'terms_of_use' => 1,
        ])->assertStatus(422);

        Mail::assertNothingSent();
    }

    public function test_signup_requires_the_terms_to_be_accepted(): void
    {
        $this->postJson('/v1/user/signup', [
            'username' => 'fresh'.substr(uniqid(), -6),
            'email' => 'fresh-'.uniqid().'@example.com',
            'password' => 'signup-password',
            'first_name' => 'Ada', 'last_name' => 'Lovelace',
            'terms_of_use' => 0,
        ])->assertStatus(422);

        Mail::assertNothingSent();
    }
}
