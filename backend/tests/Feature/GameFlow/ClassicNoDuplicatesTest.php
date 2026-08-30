<?php

namespace Tests\Feature\GameFlow;

use App\Enums\DifficultyTier;
use App\Events\GameFinished;
use App\Events\RoundStarted;
use App\Events\RoundWon;
use App\Events\TierAdvanced;
use App\Jobs\AdvanceRoundStage;
use App\Jobs\ExpandSongPool;
use App\Models\GameRoom;
use App\Models\RoomPlayer;
use App\Models\Song;
use App\Models\User;
use App\Services\RoundService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class ClassicNoDuplicatesTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Queue::fake([AdvanceRoundStage::class, ExpandSongPool::class]);
    }

    public function test_starting_a_classic_round_records_the_song_in_the_hosts_history(): void
    {
        Event::fake([RoundStarted::class]);
        $this->fakeDeezerTrackRefresh();

        $host = User::factory()->create();
        $song = Song::factory()->forTier(DifficultyTier::Easy)->create(['genre' => 'iconic']);
        $room = GameRoom::factory()->for($host, 'host')->create([
            'mode' => 'classic',
            'genre' => 'iconic',
            'songs_per_tier' => 1,
        ]);

        app(RoundService::class)->start($room);

        $this->assertTrue($host->songPlays()->where('songs.id', $song->id)->exists());
    }

    public function test_a_song_already_in_the_hosts_history_is_skipped_when_a_fresh_one_exists(): void
    {
        Event::fake([RoundStarted::class]);
        $this->fakeDeezerTrackRefresh();

        $host = User::factory()->create();
        $alreadyPlayed = Song::factory()->forTier(DifficultyTier::Easy)->create(['genre' => 'iconic']);
        $fresh = Song::factory()->forTier(DifficultyTier::Easy)->create(['genre' => 'iconic']);
        $host->songPlays()->attach($alreadyPlayed->id);

        $room = GameRoom::factory()->for($host, 'host')->create([
            'mode' => 'classic',
            'genre' => 'iconic',
            'songs_per_tier' => 1,
        ]);

        $round = app(RoundService::class)->start($room);

        $this->assertSame($fresh->id, $round->song_id);
    }

    /**
     * The host's history is a soft preference, not a hard cap - once it
     * covers everything available for a tier, a round must still start
     * (via the existing pickFallback() last-resort reuse), never block.
     */
    public function test_a_round_still_starts_when_the_hosts_history_covers_the_entire_tier_pool(): void
    {
        Event::fake([RoundStarted::class]);
        $this->fakeDeezerTrackRefresh();

        $host = User::factory()->create();
        $onlySong = Song::factory()->forTier(DifficultyTier::Easy)->create(['genre' => 'iconic']);
        $host->songPlays()->attach($onlySong->id);

        $room = GameRoom::factory()->for($host, 'host')->create([
            'mode' => 'classic',
            'genre' => 'iconic',
            'songs_per_tier' => 1,
        ]);

        $round = app(RoundService::class)->start($room);

        $this->assertSame($onlySong->id, $round->song_id);
    }

    /**
     * The host's cross-game no-repeat memory is no longer Classic-only -
     * every mode (Custom, Battle Royale) records played songs into it too,
     * so a host replaying e.g. the same artist pool doesn't get a wall of
     * repeats.
     */
    public function test_starting_a_custom_mode_round_records_the_song_in_the_hosts_history(): void
    {
        Event::fake([RoundStarted::class]);
        $this->fakeDeezerTrackRefresh();

        $host = User::factory()->create();
        $song = Song::factory()->forTier(DifficultyTier::Easy)->create();
        $room = GameRoom::factory()->for($host, 'host')->create([
            'mode' => 'custom',
            'genre' => 'normal',
            'songs_per_tier' => 1,
        ]);

        app(RoundService::class)->start($room);

        $this->assertTrue($host->songPlays()->where('songs.id', $song->id)->exists());
    }

    public function test_a_song_in_the_hosts_history_is_skipped_in_a_custom_mode_room_when_a_fresh_one_exists(): void
    {
        Event::fake([RoundStarted::class]);
        $this->fakeDeezerTrackRefresh();

        $host = User::factory()->create();
        $alreadyPlayed = Song::factory()->forTier(DifficultyTier::Easy)->create();
        $fresh = Song::factory()->forTier(DifficultyTier::Easy)->create();
        $host->songPlays()->attach($alreadyPlayed->id);

        $room = GameRoom::factory()->for($host, 'host')->create([
            'mode' => 'custom',
            'genre' => 'normal',
            'songs_per_tier' => 1,
        ]);

        $round = app(RoundService::class)->start($room);

        $this->assertSame($fresh->id, $round->song_id);
    }

    public function test_finishing_the_hosts_79th_classic_game_does_not_clear_their_history(): void
    {
        Event::fake([RoundWon::class, TierAdvanced::class, GameFinished::class]);
        $this->fakeDeezerTrackRefresh();

        $host = User::factory()->create();
        $marker = Song::factory()->forTier(DifficultyTier::Easy)->create(['genre' => 'iconic']);
        $host->songPlays()->attach($marker->id);

        GameRoom::factory()->for($host, 'host')->count(78)->create([
            'mode' => 'classic',
            'status' => 'finished',
        ]);

        $this->finishOneClassicGame($host);

        $this->assertTrue($host->songPlays()->where('songs.id', $marker->id)->exists());
    }

    public function test_finishing_the_hosts_80th_classic_game_clears_their_history(): void
    {
        Event::fake([RoundWon::class, TierAdvanced::class, GameFinished::class]);
        $this->fakeDeezerTrackRefresh();

        $host = User::factory()->create();
        $marker = Song::factory()->forTier(DifficultyTier::Easy)->create(['genre' => 'iconic']);
        $host->songPlays()->attach($marker->id);

        GameRoom::factory()->for($host, 'host')->count(79)->create([
            'mode' => 'classic',
            'status' => 'finished',
        ]);

        $this->finishOneClassicGame($host);

        $this->assertSame(0, $host->songPlays()->count());
    }

    /**
     * The 80th-finished-game reset counts every finished game by the host,
     * not just Classic ones - a host who only plays Custom/Battle Royale
     * still gets their ever-growing history pruned on the same cadence.
     */
    public function test_finishing_the_hosts_80th_game_clears_their_history_regardless_of_mode(): void
    {
        Event::fake([RoundWon::class, TierAdvanced::class, GameFinished::class]);
        $this->fakeDeezerTrackRefresh();

        $host = User::factory()->create();
        $marker = Song::factory()->forTier(DifficultyTier::Easy)->create();
        $host->songPlays()->attach($marker->id);

        GameRoom::factory()->for($host, 'host')->count(40)->create([
            'mode' => 'classic',
            'status' => 'finished',
        ]);
        GameRoom::factory()->for($host, 'host')->count(39)->create([
            'mode' => 'custom',
            'status' => 'finished',
        ]);

        $this->finishOneCustomGame($host);

        $this->assertSame(0, $host->songPlays()->count());
    }

    /**
     * Single-tier room so the one round played is also the game's last -
     * a real guess through the HTTP endpoint so GuessService/RoundService's
     * actual finish path (not a hand-rolled shortcut) is what's exercised.
     */
    private function finishOneClassicGame(User $host): void
    {
        $this->finishOneGame($host, 'classic', 'iconic');
    }

    private function finishOneCustomGame(User $host): void
    {
        $this->finishOneGame($host, 'custom', 'normal');
    }

    private function finishOneGame(User $host, string $mode, string $genre): void
    {
        $song = Song::factory()->forTier(DifficultyTier::Extreme)->create(['genre' => $genre]);
        $room = GameRoom::factory()->for($host, 'host')->create([
            'status' => 'active',
            'mode' => $mode,
            'genre' => $genre,
            'songs_per_tier' => 1,
            'enabled_tiers' => ['extreme'],
            'current_tier' => 'extreme',
        ]);
        $round = $room->rounds()->create([
            'song_id' => $song->id,
            'tier' => DifficultyTier::Extreme->value,
            'snippet_stage' => 0.1,
            'stage_started_at' => now(),
            'status' => 'playing',
            'stage_version' => 1,
        ]);

        $player = $room->players()->create([
            'nickname' => 'HostSeat',
            'connection_token' => RoomPlayer::generateConnectionToken(),
        ]);

        $this->withHeader('X-Player-Token', $player->connection_token)
            ->postJson("/api/rounds/{$round->id}/guess", ['guess' => $song->title])
            ->assertOk();
    }
}
