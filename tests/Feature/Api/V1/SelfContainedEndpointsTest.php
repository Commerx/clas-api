<?php

namespace Tests\Feature\Api\V1;

use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Hunters tags, chat, management units and fishing regulations.
 *
 * The tags and chat endpoints write, so everything these tests create belongs
 * to the fixture account and is removed in tearDown. The reference endpoints
 * only read.
 *
 * Unit number 3 is the useful fixture here: it exists in Manitoba, New
 * Brunswick, Nova Scotia, Ontario, Saskatchewan and Yukon, which is the
 * collision the old API resolved by number alone.
 */
class SelfContainedEndpointsTest extends TestCase
{
    private object $user;

    private int $otherUserId;

    private const SPECIES_ID = 1;

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
        $this->otherUserId = (int) DB::table('user')
            ->where('id', '!=', $user->id)
            ->whereNull('blocked_at')
            ->value('id');

        $this->clearFixtureRows();
    }

    protected function tearDown(): void
    {
        if (isset($this->user)) {
            $this->clearFixtureRows();
        }

        parent::tearDown();
    }

    /** Everything these tests can create, for the fixture account only. */
    private function clearFixtureRows(): void
    {
        $tagIds = DB::table('hunters_match_tags')
            ->where('user_id', $this->user->id)
            ->pluck('id');

        if ($tagIds->isNotEmpty()) {
            DB::table('hunters_match_tags_wmu_zones')->whereIn('tag_id', $tagIds)->delete();
            DB::table('hunters_match_tags')->whereIn('id', $tagIds)->delete();
        }

        DB::table('chat_history')->where('user_id', $this->user->id)->delete();
    }

    private function token(): string
    {
        return $this->user->auth_key;
    }

    /** @return array<string,mixed> */
    private function tagPayload(array $overrides = []): array
    {
        return array_merge([
            'token' => $this->token(),
            'tag_required' => 'false',
            'species_id' => self::SPECIES_ID,
            'species_gender' => 'Antlered',
            'province' => 'SK',
            'wmus' => ['3'],
            'status' => 'Active',
        ], $overrides);
    }

    // ---- hunters tags: the units a tag is filed against ----------------

    /**
     * A unit is resolved by province *and* number.
     *
     * The old API looked it up by number alone, so a Saskatchewan tag for
     * unit 3 could be filed against Manitoba's unit 3 — a different place,
     * 1,000 km away — and 3 rows in the live table are in that state. The
     * hunter is then alerted about the wrong province and missed for theirs.
     */
    public function test_a_tag_is_filed_against_the_unit_in_its_own_province(): void
    {
        $id = $this->postJson('/v1/hunters-tags/add-tag', $this->tagPayload())
            ->assertStatus(201)->json('data.id');

        $provinces = DB::table('hunters_match_tags_wmu_zones as zones')
            ->join('wmus', 'wmus.wmu_id', '=', 'zones.wmu_id')
            ->where('zones.tag_id', $id)
            ->pluck('wmus.province')
            ->all();

        $this->assertSame(['SK'], $provinces,
            'The zone must point at the unit in the province the tag names.');
    }

    /**
     * An unknown unit is refused, and no reference row is invented.
     *
     * The old API inserted whatever the client sent into `wmus`, with no
     * province: 58 rows in the live table were created that way, with numbers
     * like "00539" and "69A-2". A tag against a unit that does not exist
     * cannot match an alert either, so this is a validation failure.
     */
    public function test_an_unknown_unit_is_refused_and_not_created(): void
    {
        $before = DB::table('wmus')->count();

        $this->postJson('/v1/hunters-tags/add-tag', $this->tagPayload([
            'wmus' => ['3', 'NOT-A-UNIT'],
        ]))->assertStatus(422)->assertJsonPath('errors.wmus.0',
            'No management units match that province and list.');

        $this->assertSame($before, DB::table('wmus')->count(),
            'A client must not be able to add rows to the unit reference table.');

        $this->assertSame(0, DB::table('hunters_match_tags')
            ->where('user_id', $this->user->id)->count(),
            'Nothing should have been written when a unit did not resolve.');
    }

    /** A unit that exists, but in another province, is still unknown here. */
    public function test_a_unit_from_another_province_is_refused(): void
    {
        $this->postJson('/v1/hunters-tags/add-tag', $this->tagPayload([
            'province' => 'SK',
            'wmus' => ['1A'],
        ]))->assertStatus(422);
    }

    // ---- hunters tags: whose tag is it --------------------------------

    public function test_a_tag_belonging_to_somebody_else_is_not_readable(): void
    {
        $id = (int) DB::table('hunters_match_tags')->insertGetId([
            'user_id' => $this->otherUserId,
            'species_id' => self::SPECIES_ID,
            'species_gender' => 'Antlered',
            'province' => 'SK',
            'status' => 'Active',
        ]);

        try {
            $this->getJson('/v1/hunters-tags/get-tag?token='.$this->token().'&tag_id='.$id)
                ->assertStatus(404);

            $this->postJson('/v1/hunters-tags/delete-tag', [
                'token' => $this->token(), 'tag_id' => $id,
            ])->assertStatus(404);

            $this->assertTrue(DB::table('hunters_match_tags')->where('id', $id)->exists(),
                'The other account\'s tag must still be there.');
        } finally {
            DB::table('hunters_match_tags')->where('id', $id)->delete();
        }
    }

    public function test_a_tag_round_trips_with_its_units(): void
    {
        $id = $this->postJson('/v1/hunters-tags/add-tag', $this->tagPayload([
            'wmus' => '3,4',
        ]))->assertStatus(201)->json('data.id');

        $item = $this->getJson('/v1/hunters-tags/get-tag?token='.$this->token().'&tag_id='.$id)
            ->assertStatus(200)->json('data.item');

        $this->assertSame(['3', '4'], $item['wmus']);
        $this->assertSame('SK', $item['province']);
        $this->assertNull($item['tag_number'], 'tag_required false means no tag number.');
        $this->assertIsInt($item['id']);
        $this->assertIsInt($item['species_id']);
    }

    /** The status column is an enum; the old API accepted any string. */
    public function test_an_unknown_status_is_refused(): void
    {
        $this->postJson('/v1/hunters-tags/add-tag', $this->tagPayload([
            'status' => 'Whatever',
        ]))->assertStatus(422);
    }

    /** A season that ends before it starts is not a season. */
    public function test_an_end_date_before_the_start_is_refused(): void
    {
        $this->postJson('/v1/hunters-tags/add-tag', $this->tagPayload([
            'tag_required' => 'true',
            'tag_number' => 12345,
            'wildlife_id_number' => 67890,
            'season_start_date' => '2026-09-01',
            'season_end_date' => '2026-08-01',
        ]))->assertStatus(422);
    }

    // ---- hunters tags: expiry ------------------------------------------

    /**
     * Listing tags expires the caller's own, and nobody else's.
     *
     * The old endpoint ran `updateAll` over the whole table, so one hunter
     * opening this screen rewrote every other hunter's rows.
     */
    public function test_listing_tags_expires_only_the_callers_own(): void
    {
        $mine = (int) DB::table('hunters_match_tags')->insertGetId([
            'user_id' => $this->user->id,
            'species_id' => self::SPECIES_ID,
            'species_gender' => 'Antlered',
            'province' => 'SK',
            'status' => 'Active',
            'season_end_date' => '2020-01-01',
        ]);

        $theirs = (int) DB::table('hunters_match_tags')->insertGetId([
            'user_id' => $this->otherUserId,
            'species_id' => self::SPECIES_ID,
            'species_gender' => 'Antlered',
            'province' => 'SK',
            'status' => 'Active',
            'season_end_date' => '2020-01-01',
        ]);

        try {
            $this->getJson('/v1/hunters-tags/get-all-tags?token='.$this->token())
                ->assertStatus(200);

            $this->assertSame('Expired', DB::table('hunters_match_tags')
                ->where('id', $mine)->value('status'));

            $this->assertSame('Active', DB::table('hunters_match_tags')
                ->where('id', $theirs)->value('status'),
                'Another hunter\'s tag must not be touched by my read.');
        } finally {
            DB::table('hunters_match_tags')->whereIn('id', [$mine, $theirs])->delete();
        }
    }

    // ---- chat ----------------------------------------------------------

    public function test_a_conversation_round_trips(): void
    {
        $id = $this->postJson('/v1/chat/add-history', [
            'token' => $this->token(),
            'question' => 'Where can I hunt elk near Waldron?',
            'history' => ['one', 'two'],
        ])->assertStatus(201)->json('data.id');

        $data = $this->getJson('/v1/chat/get-history?token='.$this->token().'&chat_id='.$id)
            ->assertStatus(200)->json('data');

        $this->assertSame('Where can I hunt elk near Waldron?', $data['chat_title']);
        $this->assertSame(['one', 'two'], $data['history']);

        $this->getJson('/v1/chat/list?token='.$this->token())
            ->assertStatus(200)
            ->assertJsonPath('data.items.0.id', $id);
    }

    public function test_a_conversation_belonging_to_somebody_else_is_not_readable(): void
    {
        $id = (int) DB::table('chat_history')->insertGetId([
            'user_id' => $this->otherUserId,
            'chat_title' => 'Not mine',
            'history' => '[]',
        ]);

        try {
            $this->getJson('/v1/chat/get-history?token='.$this->token().'&chat_id='.$id)
                ->assertStatus(404);

            $this->postJson('/v1/chat/delete-history', [
                'token' => $this->token(), 'chat_id' => $id,
            ])->assertStatus(404);

            $this->assertTrue(DB::table('chat_history')->where('id', $id)->exists());
        } finally {
            DB::table('chat_history')->where('id', $id)->delete();
        }
    }

    /** The column is LONGTEXT and the old endpoints had no limit at all. */
    public function test_an_oversized_history_is_refused(): void
    {
        $this->postJson('/v1/chat/add-history', [
            'token' => $this->token(),
            'question' => 'Too much',
            'history' => [str_repeat('x', 1_100_000)],
        ])->assertStatus(422)
            ->assertJsonPath('errors.history.0', 'That history is too large to store.');

        $this->assertSame(0, DB::table('chat_history')
            ->where('user_id', $this->user->id)->count());
    }

    public function test_updating_a_conversation_moves_it_to_the_top_of_the_list(): void
    {
        $first = $this->postJson('/v1/chat/add-history', [
            'token' => $this->token(), 'question' => 'First', 'history' => ['a'],
        ])->json('data.id');

        $second = $this->postJson('/v1/chat/add-history', [
            'token' => $this->token(), 'question' => 'Second', 'history' => ['b'],
        ])->json('data.id');

        $this->postJson('/v1/chat/update-history', [
            'token' => $this->token(), 'chat_id' => $first, 'history' => ['a', 'c'],
        ])->assertStatus(200);

        $this->getJson('/v1/chat/list?token='.$this->token())
            ->assertStatus(200)
            ->assertJsonPath('data.items.0.id', $first);

        $this->assertNotSame($first, $second);
    }

    // ---- reference data ------------------------------------------------

    public function test_a_management_unit_is_read_for_the_province_asked_for(): void
    {
        $sk = $this->getJson('/v1/wmu/games?token='.$this->token().'&id=3&province=SK')
            ->assertStatus(200)->json('data.wmu');

        $mb = $this->getJson('/v1/wmu/games?token='.$this->token().'&id=3&province=MB')
            ->assertStatus(200)->json('data.wmu');

        $this->assertSame('SK', $sk['province']);
        $this->assertSame('MB', $mb['province']);
        $this->assertNotSame($sk['wmu_id'], $mb['wmu_id'],
            'Unit 3 is a different unit in each province.');
    }

    public function test_an_unknown_management_unit_answers_not_found(): void
    {
        $this->getJson('/v1/wmu/games?token='.$this->token().'&id=NOT-A-UNIT&province=SK')
            ->assertStatus(404);
    }

    /** The games list is distinct; the old one repeated a game per detail. */
    public function test_the_games_list_has_no_duplicates(): void
    {
        $games = $this->getJson('/v1/wmu/games?token='.$this->token().'&id=3&province=SK')
            ->assertStatus(200)->json('data.games');

        $ids = array_column($games, 'game_id');
        $this->assertSame($ids, array_values(array_unique($ids)));
    }

    /**
     * A province goes into a table name, so only the nine that have one are
     * accepted. This is the injection guard, not a convenience.
     */
    public function test_a_province_with_no_season_table_is_refused(): void
    {
        foreach (['QC', 'NL', 'ab_season_details', "ab'--"] as $province) {
            $this->getJson('/v1/wmu/game-season-details?token='.$this->token()
                .'&wmu_id=3&game_detail_id=1&province='.urlencode($province))
                ->assertStatus(422);
        }
    }

    public function test_season_details_come_back_with_native_types(): void
    {
        $items = $this->getJson('/v1/wmu/game-season-details?token='.$this->token()
            .'&wmu_id=3&game_detail_id=1&province=ab')
            ->assertStatus(200)->json('data.items');

        $this->assertNotEmpty($items, 'Game detail 1 has seasons recorded.');
        $this->assertIsInt($items[0]['season_id']);
        $this->assertIsInt($items[0]['species_id']);
    }

    /** 13 of the 174 species names hold a carriage return and newline. */
    public function test_species_names_have_their_whitespace_collapsed(): void
    {
        $items = $this->getJson('/v1/wmu/species?token='.$this->token())
            ->assertStatus(200)->json('data.items');

        $this->assertNotEmpty($items);

        foreach ($items as $species) {
            $this->assertDoesNotMatchRegularExpression('/[\r\n\t]|\s{2,}/',
                (string) $species['species_name'],
                'A name reaching the app should not carry control characters.');
        }
    }

    public function test_fishing_regulations_are_listed_and_read_per_province(): void
    {
        $items = $this->getJson('/v1/fishing-lands/province-regulations?token='.$this->token())
            ->assertStatus(200)->json('data.items');

        $this->assertNotEmpty($items);
        $this->assertIsInt($items[0]['id']);

        $this->getJson('/v1/fishing-lands/regulations?token='.$this->token().'&province=AB')
            ->assertStatus(200)
            ->assertJsonPath('data.item.province', 'AB');

        $this->getJson('/v1/fishing-lands/regulations?token='.$this->token().'&province=ZZ')
            ->assertStatus(404);
    }
}
