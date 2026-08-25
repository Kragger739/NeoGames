<?php

namespace Tests\Feature\GameFlow;

use App\Enums\DifficultyTier;
use App\Events\GameFinished;
use App\Events\RoomReset;
use App\Events\RoundStarted;
use App\Events\RoundWon;
use App\Events\TierAdvanced;
use App\Jobs\AdvanceRoundStage;
use App\Jobs\ExpandSongPool;
use App\Models\GameRoom;
use App\Models\Song;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class RoundLifecycleTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // Prevents the stage-timeout job from firing immediately under the
        // test env's sync queue connection (which ignores delay()) - these
        // tests exercise guess-driven resolution, not the timeout path.
        Queue::fake([AdvanceRoundStage::class, ExpandSongPool::class]);
    }

    /**
     * Seeds the shared song cache with `count` songs per tier, so
     * SongDiscoveryService finds candidates without ever hitting Spotify.
     */
    private function seedSongsForAllTiers(int $count = 1): void
    {
        // Starting a round now also re-confirms the picked song's preview
        // is still live (RoundService::pickPlayableSong()), which makes one
        // real GET /track/{id} call unless faked here.
        $this->fakeDeezerTrackRefresh();

        foreach (DifficultyTier::ordered() as $tier) {
            Song::factory()->forTier($tier)->count($count)->create();
        }
    }

    public function test_starting_a_room_creates_the_first_round_and_broadcasts_it(): void
    {
        Event::fake([RoundStarted::class]);

        $this->seedSongsForAllTiers();
        $host = User::factory()->create();
        $room = GameRoom::factory()->for($host, 'host')->create(['songs_per_tier' => 1]);

        $response = $this->actingAs($host)->postJson("/api/rooms/{$room->code}/start");

        $response->assertOk();
        $response->assertJsonPath('status', 'active');
        $this->assertDatabaseHas('rounds', ['room_id' => $room->id, 'tier' => 'easy']);
        Event::assertDispatched(RoundStarted::class);
    }

    public function test_starting_a_room_fails_when_no_matching_song_can_be_found(): void
    {
        // No songs seeded, and the live chart returns nothing usable either.
        Http::fake([
            'api.deezer.com/chart/0/tracks*' => Http::response(['data' => []], 200),
        ]);

        $host = User::factory()->create();
        $room = GameRoom::factory()->for($host, 'host')->create(['songs_per_tier' => 1]);

        $response = $this->actingAs($host)->postJson("/api/rooms/{$room->code}/start");

        $response->assertUnprocessable();
    }

    /**
     * Deezer's preview URL is a short-lived signed link - a cached song's
     * preview_url can easily be stale by the time it's actually picked for
     * a round, so starting a round must re-fetch and persist a fresh one
     * rather than trusting whatever's sitting in the cache.
     */
    public function test_starting_a_round_refreshes_a_stale_preview_url_before_broadcasting(): void
    {
        Event::fake([RoundStarted::class]);

        $stale = Song::factory()->forTier(DifficultyTier::Easy)->create([
            'preview_url' => 'https://example.com/stale-expired.mp3',
        ]);

        Http::fake([
            "api.deezer.com/track/{$stale->deezer_track_id}" => Http::response([
                'id' => $stale->deezer_track_id,
                'title' => $stale->title,
                'artist' => ['name' => $stale->artist],
                'album' => ['cover_medium' => null],
                'preview' => 'https://example.com/freshly-refreshed.mp3',
                'rank' => 50_000,
            ], 200),
        ]);

        $host = User::factory()->create();
        $room = GameRoom::factory()->for($host, 'host')->create(['songs_per_tier' => 1]);

        app(\App\Services\RoundService::class)->start($room);

        $this->assertSame('https://example.com/freshly-refreshed.mp3', $stale->fresh()->preview_url);

        Event::assertDispatched(
            RoundStarted::class,
            fn (RoundStarted $event) => $event->broadcastWith()['audio_url'] === 'https://example.com/freshly-refreshed.mp3',
        );
    }

    /**
     * A cached song can fail its preview refresh entirely (removed from
     * Deezer, newly region-blocked, etc.) - the round must skip it and try
     * the next candidate instead of failing outright while a perfectly
     * playable alternative is sitting right there in the cache.
     */
    public function test_a_song_whose_preview_refresh_fails_is_skipped_for_the_next_candidate(): void
    {
        Event::fake([RoundStarted::class]);

        $dead = Song::factory()->forTier(DifficultyTier::Easy)->create();
        $alive = Song::factory()->forTier(DifficultyTier::Easy)->create();

        Http::fake([
            "api.deezer.com/track/{$dead->deezer_track_id}" => Http::response([
                'error' => ['type' => 'DataException', 'message' => 'no data', 'code' => 800],
            ], 200),
            "api.deezer.com/track/{$alive->deezer_track_id}" => Http::response([
                'id' => $alive->deezer_track_id,
                'title' => $alive->title,
                'artist' => ['name' => $alive->artist],
                'album' => ['cover_medium' => null],
                'preview' => 'https://example.com/alive-fresh.mp3',
                'rank' => 50_000,
            ], 200),
        ]);

        $host = User::factory()->create();
        $room = GameRoom::factory()->for($host, 'host')->create(['songs_per_tier' => 1]);

        $round = app(\App\Services\RoundService::class)->start($room);

        $this->assertSame($alive->id, $round->song_id);
        $this->assertSame('https://example.com/alive-fresh.mp3', $alive->fresh()->preview_url);
    }

    public function test_a_non_host_cannot_start_someone_elses_room(): void
    {
        $this->seedSongsForAllTiers();
        $host = User::factory()->create();
        $otherHost = User::factory()->create();
        $room = GameRoom::factory()->for($host, 'host')->create(['songs_per_tier' => 1]);

        $response = $this->actingAs($otherHost)->postJson("/api/rooms/{$room->code}/start");

        $response->assertForbidden();
    }

    public function test_a_correct_guess_wins_the_round_awards_points_and_starts_the_next_round(): void
    {
        Event::fake([RoundWon::class, RoundStarted::class]);

        $this->seedSongsForAllTiers(count: 2);
        $host = User::factory()->create();
        $room = GameRoom::factory()->for($host, 'host')->create(['songs_per_tier' => 2]);
        app(\App\Services\RoundService::class)->start($room);

        $round = $room->rounds()->first();
        $player = $room->players()->create([
            'nickname' => 'Alice',
            'connection_token' => \App\Models\RoomPlayer::generateConnectionToken(),
        ]);

        $response = $this->withHeader('X-Player-Token', $player->connection_token)
            ->postJson("/api/rounds/{$round->id}/guess", ['guess' => $round->song->title]);

        $response->assertOk();
        $response->assertJson(['correct' => true, 'won' => true]);

        $round->refresh();
        $this->assertSame('won', $round->status->value);
        $this->assertSame($player->id, $round->winning_player_id);

        $player->refresh();
        $this->assertGreaterThan(0, $player->score);

        Event::assertDispatched(RoundWon::class, function (RoundWon $event) use ($player) {
            $scoreboard = $event->broadcastWith()['scoreboard'];

            return $scoreboard->count() === 1
                && $scoreboard->first()->id === $player->id
                && $scoreboard->first()->score > 0;
        });
        // Started once for the room's first round, again for the next.
        Event::assertDispatched(RoundStarted::class, 2);
    }

    public function test_round_won_broadcasts_the_songs_album_art(): void
    {
        // songs_per_tier=1 means the win also advances the tier, which
        // would otherwise dispatch StartNextRound for real (sync queue) and
        // try to find/broadcast an Intermediate-tier round this test never
        // seeded, and (now that broadcasts are ShouldBroadcastNow, sent
        // immediately rather than via a fakeable queued job) actually
        // attempt a real TierAdvanced broadcast - both irrelevant to what's
        // being asserted here, so TierAdvanced needs its own explicit fake
        // alongside RoundWon; Queue::fake() alone no longer covers it.
        Event::fake([RoundWon::class, TierAdvanced::class]);
        Queue::fake();

        $host = User::factory()->create();
        $room = GameRoom::factory()->for($host, 'host')->create(['songs_per_tier' => 1]);
        $song = Song::factory()->forTier(DifficultyTier::Easy)->create([
            'album_art_url' => 'https://example.com/art-100.jpg',
        ]);
        $room->update(['current_tier' => DifficultyTier::Easy->value]);
        $round = $room->rounds()->create([
            'song_id' => $song->id,
            'tier' => DifficultyTier::Easy->value,
            'snippet_stage' => 0.1,
            'stage_started_at' => now(),
            'status' => 'playing',
            'stage_version' => 1,
        ]);

        $player = $room->players()->create([
            'nickname' => 'Alice',
            'connection_token' => \App\Models\RoomPlayer::generateConnectionToken(),
        ]);

        $this->withHeader('X-Player-Token', $player->connection_token)
            ->postJson("/api/rounds/{$round->id}/guess", ['guess' => $song->title])
            ->assertOk();

        Event::assertDispatched(
            RoundWon::class,
            fn (RoundWon $event) => $event->broadcastWith()['answer']['album_art_url'] === 'https://example.com/art-100.jpg',
        );
    }

    /**
     * The reveal's "how popular is this" stat - Deezer has no per-song
     * count, only a per-artist fan count, fetched and cached the moment a
     * round actually resolves (see RoundService::ensureRevealStats()).
     */
    public function test_round_won_broadcasts_the_artists_fan_count(): void
    {
        // See test_round_won_broadcasts_the_songs_album_art() above for why
        // TierAdvanced needs its own explicit fake here too.
        Event::fake([RoundWon::class, TierAdvanced::class]);
        Queue::fake();

        $host = User::factory()->create();
        $room = GameRoom::factory()->for($host, 'host')->create(['songs_per_tier' => 1]);
        $song = Song::factory()->forTier(DifficultyTier::Easy)->create([
            'artist_deezer_id' => '27',
            'artist_fan_count' => null,
        ]);
        $room->update(['current_tier' => DifficultyTier::Easy->value]);
        $round = $room->rounds()->create([
            'song_id' => $song->id,
            'tier' => DifficultyTier::Easy->value,
            'snippet_stage' => 0.1,
            'stage_started_at' => now(),
            'status' => 'playing',
            'stage_version' => 1,
        ]);

        Http::fake([
            'api.deezer.com/artist/27' => Http::response(['id' => 27, 'name' => 'Daft Punk', 'nb_fan' => 5_194_479], 200),
        ]);

        $player = $room->players()->create([
            'nickname' => 'Alice',
            'connection_token' => \App\Models\RoomPlayer::generateConnectionToken(),
        ]);

        $this->withHeader('X-Player-Token', $player->connection_token)
            ->postJson("/api/rounds/{$round->id}/guess", ['guess' => $song->title])
            ->assertOk();

        Event::assertDispatched(
            RoundWon::class,
            fn (RoundWon $event) => $event->broadcastWith()['answer']['artist_fan_count'] === 5_194_479,
        );
        $this->assertSame(5_194_479, $song->fresh()->artist_fan_count);
    }

    public function test_a_wrong_guess_does_not_win_and_the_round_stays_open(): void
    {
        Event::fake();

        $this->seedSongsForAllTiers();
        $host = User::factory()->create();
        $room = GameRoom::factory()->for($host, 'host')->create(['songs_per_tier' => 1]);
        app(\App\Services\RoundService::class)->start($room);
        $round = $room->rounds()->first();

        $player = $room->players()->create([
            'nickname' => 'Alice',
            'connection_token' => \App\Models\RoomPlayer::generateConnectionToken(),
        ]);

        $response = $this->withHeader('X-Player-Token', $player->connection_token)
            ->postJson("/api/rounds/{$round->id}/guess", ['guess' => 'definitely not the song']);

        $response->assertOk();
        $response->assertJson(['correct' => false, 'won' => false]);

        $round->refresh();
        $this->assertSame('playing', $round->status->value);
    }

    public function test_a_player_cannot_guess_on_a_round_from_a_different_room(): void
    {
        Event::fake();

        $this->seedSongsForAllTiers();
        $host = User::factory()->create();
        $room = GameRoom::factory()->for($host, 'host')->create(['songs_per_tier' => 1]);
        app(\App\Services\RoundService::class)->start($room);
        $round = $room->rounds()->first();

        $otherRoom = GameRoom::factory()->create();
        $player = $otherRoom->players()->create([
            'nickname' => 'Eve',
            'connection_token' => \App\Models\RoomPlayer::generateConnectionToken(),
        ]);

        $response = $this->withHeader('X-Player-Token', $player->connection_token)
            ->postJson("/api/rounds/{$round->id}/guess", ['guess' => $round->song->title]);

        $response->assertForbidden();
    }

    public function test_tier_advances_after_songs_per_tier_rounds_are_won(): void
    {
        Event::fake([TierAdvanced::class, RoundStarted::class, RoundWon::class]);

        $this->seedSongsForAllTiers();
        $host = User::factory()->create();
        $room = GameRoom::factory()->for($host, 'host')->create(['songs_per_tier' => 1]);
        app(\App\Services\RoundService::class)->start($room);

        $round = $room->rounds()->first();
        $player = $room->players()->create([
            'nickname' => 'Alice',
            'connection_token' => \App\Models\RoomPlayer::generateConnectionToken(),
        ]);

        $this->withHeader('X-Player-Token', $player->connection_token)
            ->postJson("/api/rounds/{$round->id}/guess", ['guess' => $round->song->title])
            ->assertOk();

        $room->refresh();
        $this->assertSame('intermediate', $room->current_tier->value);
        Event::assertDispatched(TierAdvanced::class);
    }

    public function test_the_game_finishes_after_the_last_tiers_last_song_is_won(): void
    {
        Event::fake([GameFinished::class, RoundStarted::class, RoundWon::class, TierAdvanced::class]);

        $this->seedSongsForAllTiers();
        $host = User::factory()->create();
        $room = GameRoom::factory()->for($host, 'host')->create(['songs_per_tier' => 1]);
        app(\App\Services\RoundService::class)->start($room);

        $player = $room->players()->create([
            'nickname' => 'Alice',
            'connection_token' => \App\Models\RoomPlayer::generateConnectionToken(),
        ]);

        foreach (DifficultyTier::ordered() as $tier) {
            $round = $room->fresh()->rounds()->where('status', 'playing')->firstOrFail();

            $this->withHeader('X-Player-Token', $player->connection_token)
                ->postJson("/api/rounds/{$round->id}/guess", ['guess' => $round->song->title])
                ->assertOk();
        }

        $room->refresh();
        $this->assertSame('finished', $room->status->value);
        Event::assertDispatched(GameFinished::class);
    }

    public function test_the_host_can_redo_a_finished_room_which_resets_it_to_the_lobby(): void
    {
        Event::fake([GameFinished::class, RoundStarted::class, RoundWon::class, TierAdvanced::class, RoomReset::class]);

        $this->seedSongsForAllTiers();
        $host = User::factory()->create();
        $room = GameRoom::factory()->for($host, 'host')->create(['songs_per_tier' => 1]);
        app(\App\Services\RoundService::class)->start($room);

        $player = $room->players()->create([
            'nickname' => 'Alice',
            'connection_token' => \App\Models\RoomPlayer::generateConnectionToken(),
        ]);

        // Guesses submitted directly through the service, not HTTP - mixing
        // a real 'player'-guard HTTP call with the later 'sanctum'-guard
        // actingAs() call in the same test hits the same guard-caching
        // gotcha documented in GuessRaceConditionTest/LevelingTest.
        $guessService = app(\App\Services\GuessService::class);

        foreach (DifficultyTier::ordered() as $tier) {
            $round = $room->fresh()->rounds()->where('status', 'playing')->firstOrFail();
            $guessService->submit($round, $player, $round->song->title);
        }

        $room->refresh();
        $this->assertSame('finished', $room->status->value);
        $this->assertGreaterThan(0, $player->fresh()->score);

        $response = $this->actingAs($host)->postJson("/api/rooms/{$room->code}/redo");

        $response->assertOk();
        $response->assertJsonPath('status', 'lobby');
        $response->assertJsonPath('current_tier', 'easy');
        $response->assertJsonPath('current_song_index', 0);

        $room->refresh();
        $this->assertSame('lobby', $room->status->value);
        $this->assertSame('easy', $room->current_tier->value);
        $this->assertSame(0, $room->current_song_index);
        $this->assertSame(0, $player->fresh()->score);

        Event::assertDispatched(RoomReset::class);
    }

    public function test_a_non_host_cannot_redo_someone_elses_room(): void
    {
        $this->seedSongsForAllTiers();
        $host = User::factory()->create();
        $otherHost = User::factory()->create();
        $room = GameRoom::factory()->for($host, 'host')->create(['status' => 'finished']);

        $response = $this->actingAs($otherHost)->postJson("/api/rooms/{$room->code}/redo");

        $response->assertForbidden();
    }

    public function test_redo_fails_if_the_room_has_not_finished(): void
    {
        $host = User::factory()->create();
        $room = GameRoom::factory()->for($host, 'host')->create(['status' => 'lobby']);

        $response = $this->actingAs($host)->postJson("/api/rooms/{$room->code}/redo");

        $response->assertUnprocessable();
    }
}
