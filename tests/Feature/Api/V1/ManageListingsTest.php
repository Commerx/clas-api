<?php

namespace Tests\Feature\Api\V1;

use App\Mail\ManagerInvited;
use App\Support\V1\FeatureList;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

/**
 * Managing a listing: who may, and what comes back.
 *
 * These write to the shared database, so everything created belongs to the
 * fixture account and fixture listing and is removed in tearDown. Photo
 * uploads are pointed at a temporary directory rather than the old web
 * application's document root.
 */
class ManageListingsTest extends TestCase
{
    private object $user;

    private int $landId;

    private array $landBefore;

    private int $otherLandId;

    private int $otherUserId;

    private string $photoRoot;

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

        $land = DB::table('land')->where('land_name', 'Contract Fixture Land')->first();

        if ($land === null) {
            $this->markTestSkipped('Fixture listing missing; see tests/Fixtures/v1/README.md.');
        }

        $this->user = $user;
        $this->landId = (int) $land->land_id;
        $this->landBefore = (array) $land;

        // A listing this account neither owns nor manages.
        $this->otherLandId = (int) DB::table('land')
            ->where('owner', '!=', $user->id)
            ->whereNotIn('land_id', function ($q) use ($user) {
                $q->select('land_id')->from('land_managers')->where('user_id', $user->id);
            })
            ->value('land_id');

        $this->otherUserId = (int) DB::table('user')
            ->where('id', '!=', $user->id)
            ->whereNull('blocked_at')
            ->value('id');

        $this->photoRoot = storage_path('framework/testing/photos-'.getmypid());
        config(['clas.uploads.photos_path' => $this->photoRoot]);

        $this->clearFixtureRows();

        Mail::fake();
    }

    protected function tearDown(): void
    {
        if (isset($this->landId)) {
            $this->clearFixtureRows();
            DB::table('land')->where('land_id', $this->landId)->update($this->landBefore);
        }

        if (isset($this->photoRoot) && is_dir($this->photoRoot)) {
            File::deleteDirectory($this->photoRoot);
        }

        parent::tearDown();
    }

    private function clearFixtureRows(): void
    {
        DB::table('photos')->where('land_id', $this->landId)->delete();
        DB::table('land_managers')->where('land_id', $this->landId)->delete();
        DB::table('land')->where('owner', $this->user->id)
            ->where('land_id', '!=', $this->landId)->delete();
    }

    private function token(): string
    {
        return $this->user->auth_key;
    }

    // ---- the endpoint that had no access check -------------------------

    /**
     * `detail` is the one this group exists for.
     *
     * The old endpoint took a land id, selected `land.*` and answered for any
     * listing owned by anyone. It published `claim_code` — the secret that
     * lets whoever holds it take ownership of a listing — for all 6,131 rows
     * that have one.
     */
    public function test_a_listing_the_caller_does_not_manage_is_not_readable(): void
    {
        if ($this->otherLandId === 0) {
            $this->markTestSkipped('No listing owned by another account.');
        }

        $this->getJson('/v1/manage-listings/detail?token='.$this->token().'&id='.$this->otherLandId)
            ->assertStatus(404);

        $this->getJson('/v1/manage-listings/availability?token='.$this->token().'&id='.$this->otherLandId)
            ->assertStatus(404);
    }

    /** Nor may it be edited, published or taken down. */
    public function test_a_listing_the_caller_does_not_manage_cannot_be_written(): void
    {
        if ($this->otherLandId === 0) {
            $this->markTestSkipped('No listing owned by another account.');
        }

        $before = (array) DB::table('land')->where('land_id', $this->otherLandId)->first();

        foreach ([
            'update-description' => ['land_name' => 'Taken', 'land_description' => 'Taken'],
            'unpublish' => [],
            'publish' => [],
            'delete-land' => [],
        ] as $endpoint => $payload) {
            $this->postJson('/v1/manage-listings/'.$endpoint, array_merge([
                'token' => $this->token(), 'id' => $this->otherLandId,
            ], $payload))->assertStatus(404);
        }

        $this->assertSame($before, (array) DB::table('land')->where('land_id', $this->otherLandId)->first(),
            "Another owner's listing must be byte-identical afterwards.");
    }

    /** The secrets stay out of the response, for the owner too. */
    public function test_the_detail_response_carries_no_secrets(): void
    {
        $item = $this->getJson('/v1/manage-listings/detail?token='.$this->token().'&id='.$this->landId)
            ->assertStatus(200)->json('data.item');

        foreach (['claim_code', 'customer_id', 'order_id', 'txn_number'] as $column) {
            $this->assertArrayNotHasKey($column, $item,
                $column.' must not reach the API.');
        }
    }

    /** A pending invitation is not access. */
    public function test_a_pending_manager_cannot_read_the_listing(): void
    {
        $managerId = (int) DB::table('land_managers')->insertGetId([
            'land_id' => $this->otherLandId,
            'first_name' => 'Pending', 'last_name' => 'Invitee',
            'user_email' => $this->user->email,
            'user_id' => $this->user->id,
            'status' => 'pending',
        ]);

        try {
            $this->getJson('/v1/manage-listings/detail?token='.$this->token().'&id='.$this->otherLandId)
                ->assertStatus(404);

            DB::table('land_managers')->where('id', $managerId)->update(['status' => 'accepted']);

            $this->getJson('/v1/manage-listings/detail?token='.$this->token().'&id='.$this->otherLandId)
                ->assertStatus(200);
        } finally {
            DB::table('land_managers')->where('id', $managerId)->delete();
        }
    }

    // ---- managers ------------------------------------------------------

    /**
     * A manager cannot appoint or remove managers.
     *
     * The old controller let anyone with a land_managers row do both, which
     * is how an invited manager could take over the management of somebody
     * else's property.
     */
    public function test_only_the_owner_may_add_or_remove_managers(): void
    {
        $managerId = (int) DB::table('land_managers')->insertGetId([
            'land_id' => $this->otherLandId,
            'first_name' => 'Accepted', 'last_name' => 'Manager',
            'user_email' => $this->user->email,
            'user_id' => $this->user->id,
            'status' => 'accepted',
        ]);

        try {
            // Reading is allowed for an accepted manager...
            $this->getJson('/v1/manage-listings/detail?token='.$this->token().'&id='.$this->otherLandId)
                ->assertStatus(200);

            // ...appointing another manager is not.
            $this->postJson('/v1/manage-listings/add-manager', [
                'token' => $this->token(), 'id' => $this->otherLandId,
                'first_name' => 'A', 'last_name' => 'B', 'user_email' => 'nobody@example.com',
            ])->assertStatus(404);

            $this->postJson('/v1/manage-listings/delete-manager', [
                'token' => $this->token(), 'id' => $this->otherLandId, 'manager_id' => $managerId,
            ])->assertStatus(404);

            $this->assertTrue(DB::table('land_managers')->where('id', $managerId)->exists());
            $this->assertSame(0, DB::table('land_managers')
                ->where('user_email', 'nobody@example.com')->count());
        } finally {
            // Both rows: if this test ever fails at the assertion above, the
            // invitation it tried to create is real and would poison the next
            // run of the suite.
            DB::table('land_managers')->where('id', $managerId)->delete();
            DB::table('land_managers')->where('user_email', 'nobody@example.com')->delete();
        }
    }

    public function test_the_owner_invites_a_manager_and_mail_goes_out(): void
    {
        $this->postJson('/v1/manage-listings/add-manager', [
            'token' => $this->token(), 'id' => $this->landId,
            'first_name' => 'Pat', 'last_name' => 'Example',
            'user_email' => 'pat.example@example.com',
        ])->assertStatus(201)
            ->assertJsonPath('data.managers.0.status', 'pending');

        Mail::assertSent(ManagerInvited::class);

        // The same person twice is refused rather than duplicated.
        $this->postJson('/v1/manage-listings/add-manager', [
            'token' => $this->token(), 'id' => $this->landId,
            'first_name' => 'Pat', 'last_name' => 'Example',
            'user_email' => 'pat.example@example.com',
        ])->assertStatus(409);

        $this->assertSame(1, DB::table('land_managers')
            ->where('land_id', $this->landId)->count());
    }

    // ---- photos --------------------------------------------------------

    /**
     * The upload must be an image, and nothing is written until it is.
     *
     * The old endpoint took the extension from the client's file name and
     * wrote the file under the document root before anything read the
     * contents, so a caller chose the extension of a file placed in a
     * web-served directory.
     */
    public function test_a_non_image_upload_is_refused_and_nothing_is_written(): void
    {
        $this->postJson('/v1/manage-listings/upload-photos', [
            'token' => $this->token(),
            'id' => $this->landId,
            'image' => UploadedFile::fake()->createWithContent('payload.php', '<?php echo 1;'),
        ])->assertStatus(422)
            ->assertJsonPath('errors.image.0', 'That file is not an image this API accepts.');

        $this->assertSame(0, DB::table('photos')->where('land_id', $this->landId)->count());
        $this->assertSame([], $this->storedFiles(),
            'Nothing may reach the photo directory for a refused upload.');
    }

    /**
     * Real image bytes under a .php name are still stored as an image.
     *
     * This is the whole of the old endpoint's problem in one request: the
     * name says .php, the bytes say PNG, and the old code believed the name.
     */
    public function test_the_stored_extension_comes_from_the_bytes_not_the_name(): void
    {
        $item = $this->postJson('/v1/manage-listings/upload-photos', [
            'token' => $this->token(),
            'id' => $this->landId,
            'image' => UploadedFile::fake()->createWithContent('payload.php', $this->pngBytes()),
        ])->assertStatus(201)->json('data.item');

        $this->assertStringEndsWith('.png', $item['photo_name']);
        $this->assertSame('Y', $item['is_titlephoto'], 'The first photo is the title photo.');

        foreach ($this->storedFiles() as $name) {
            $this->assertStringEndsWith('.png', $name,
                'No file in the photo directory may carry another extension.');
        }
    }

    /**
     * A photo row goes even when its file does not.
     *
     * The old endpoint refused to delete the row when the original was
     * missing from disk, so a row left behind by a failed upload could not be
     * cleared through the API at all.
     */
    public function test_a_photo_row_is_removed_even_with_no_file_on_disk(): void
    {
        $photoId = (int) DB::table('photos')->insertGetId([
            'land_id' => $this->landId,
            'photo_name' => 'not-on-disk.png',
            'photo' => 'not-on-disk.png',
            'size' => 1, 'type' => 'image/png', 'is_titlephoto' => 'Y',
        ]);

        $second = (int) DB::table('photos')->insertGetId([
            'land_id' => $this->landId,
            'photo_name' => 'also-not-on-disk.png',
            'photo' => 'also-not-on-disk.png',
            'size' => 1, 'type' => 'image/png', 'is_titlephoto' => 'N',
        ]);

        $this->postJson('/v1/manage-listings/delete-photos', [
            'token' => $this->token(), 'id' => $this->landId, 'photo_id' => $photoId,
        ])->assertStatus(200);

        $this->assertFalse(DB::table('photos')->where('photo_id', $photoId)->exists());

        $this->assertSame('Y', DB::table('photos')->where('photo_id', $second)->value('is_titlephoto'),
            'Removing the title photo must leave the listing with another one.');
    }

    // ---- features ------------------------------------------------------

    /**
     * The free text in a feature selection is escaped into the stored HTML.
     *
     * `features_list` holds rendered markup that the admin listing view and
     * two mail templates render raw. The old builder interpolated the owner's
     * own text into it untouched.
     */
    public function test_feature_free_text_is_escaped_into_the_stored_html(): void
    {
        $keyFeatures = DB::table('key_features')->where('status', 'active')
            ->orderBy('sort_order')->get();

        // The "other" box renders inside a group's own list, so the parent
        // has to be one that has children — as in the old builder.
        $parent = $keyFeatures->first(fn ($feature) => (int) $feature->parent_id === 0
            && $keyFeatures->contains(fn ($child) => (int) $child->parent_id === (int) $feature->feature_id));

        if ($parent === null) {
            $this->markTestSkipped('No top-level key feature with children.');
        }

        $html = FeatureList::html(
            $parent->feature_id.'-0,<script>alert(1)</script>-other'.$parent->feature_id,
            $keyFeatures,
        );

        $this->assertStringNotContainsString('<script>', $html);
        $this->assertStringContainsString('&lt;script&gt;', $html);
    }

    public function test_an_unknown_feature_is_refused(): void
    {
        $this->postJson('/v1/manage-listings/update-features-rules', [
            'token' => $this->token(),
            'id' => $this->landId,
            'key_features' => ['99999999-0'],
        ])->assertStatus(422);
    }

    // ---- lifecycle -----------------------------------------------------

    public function test_publish_says_which_of_the_two_reasons_it_refused(): void
    {
        DB::table('land')->where('land_id', $this->landId)->update([
            'payment_status' => 'Pending', 'active' => 'N',
        ]);

        $this->postJson('/v1/manage-listings/publish', [
            'token' => $this->token(), 'id' => $this->landId,
        ])->assertStatus(409)
            ->assertJsonPath('error', 'This listing has not been paid for yet.');

        DB::table('land')->where('land_id', $this->landId)->update([
            'payment_status' => 'Completed', 'active' => 'Y',
        ]);

        $this->postJson('/v1/manage-listings/publish', [
            'token' => $this->token(), 'id' => $this->landId,
        ])->assertStatus(409)
            ->assertJsonPath('error', 'This listing is already published.');
    }

    public function test_a_new_listing_belongs_to_its_creator(): void
    {
        $item = $this->postJson('/v1/manage-listings/add', [
            'token' => $this->token(),
            'land_name' => 'Fixture Draft',
            'land_description' => 'Created by the test suite.',
        ])->assertStatus(201)->json('data.item');

        $this->assertSame((int) $this->user->id, $item['owner']);
        $this->assertNotEmpty($item['barcode']);

        $this->getJson('/v1/manage-listings/all?token='.$this->token())
            ->assertStatus(200)
            ->assertJsonPath('data.items.0.land_id', $item['land_id']);
    }

    /**
     * A listing whose party size was never set does not divide by zero.
     *
     * `add` starts a listing at people_allowed 0, and the old availability
     * endpoint divided by it.
     */
    public function test_availability_handles_a_listing_with_no_party_size(): void
    {
        DB::table('land')->where('land_id', $this->landId)->update(['people_allowed' => 0]);

        $this->getJson('/v1/manage-listings/availability?token='.$this->token().'&id='.$this->landId)
            ->assertStatus(200)
            ->assertJsonStructure(['data' => ['blocked', 'percent_filled']]);
    }

    /** A small but genuine PNG, so getimagesize reads it as one. */
    private function pngBytes(): string
    {
        $image = imagecreatetruecolor(1200, 800);
        imagefill($image, 0, 0, imagecolorallocate($image, 80, 120, 60));

        ob_start();
        imagepng($image);
        imagedestroy($image);

        return (string) ob_get_clean();
    }

    /** @return list<string> */
    private function storedFiles(): array
    {
        $directory = $this->photoRoot.'/'.$this->landId;

        if (! is_dir($directory)) {
            return [];
        }

        return array_values(array_diff(scandir($directory), ['.', '..']));
    }
}
