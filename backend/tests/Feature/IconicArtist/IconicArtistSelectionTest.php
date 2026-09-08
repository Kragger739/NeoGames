<?php

namespace Tests\Feature\IconicArtist;

use App\Jobs\ExpandSongPool;
use App\Models\GameRoom;
use App\Models\IconicArtist;
use App\Models\IconicArtistSong;
use App\Models\User;
use App\Services\RoundService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Queue;
use RuntimeException;
use Tests\TestCase;

class IconicArtistSelectionTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Event::fake();
        Queue::fake();
    }

    private function iconicRoom(IconicArtist $artist): GameRoom
    {
        $host = User::factory()->create();

        return GameRoom::factory()->for($host, 'host')->create([
            'iconic_artist_id' => $artist->id,
            'genre' => 'artist',
            'artist_name' => $artist->name,
            'player_mode' => 'solo',
            'mode' => 'custom',
            'enabled_tiers' => ['easy'],
            'songs_per_tier' => 5,
            'current_tier' => 'easy',
            'status' => 'active',
        ]);
    }

    private function rankOf(IconicArtist $artist, int $songId): ?int
    {
        return IconicArtistSong::where('iconic_artist_id', $artist->id)
            ->where('song_id', $songId)->value('rank');
    }

    public function test_normal_play_only_ever_draws_from_the_top_20(): void
    {
        $artist = IconicArtist::factory()->fetched(40)->create();
        $room = $this->iconicRoom($artist);
        $svc = app(RoundService::class);

        for ($i = 0; $i < 15; $i++) {
            $round = $svc->startNextRound($room->fresh());
            $rank = $this->rankOf($artist, $round->song_id);
            $this->assertNotNull($rank);
            $this->assertLessThanOrEqual(20, $rank);
        }

        // No cross-game no-repeat memory + no pool growth for iconic rooms.
        Queue::assertNotPushed(ExpandSongPool::class);
        $this->assertSame(0, $room->host->songPlays()->count());
    }

    public function test_it_spills_into_the_reservoir_when_the_top_20_runs_out(): void
    {
        $artist = IconicArtist::factory()->fetched(40)->create();
        // Only ranks 1-3 are playable in the top 20.
        IconicArtistSong::where('iconic_artist_id', $artist->id)
            ->where('rank', '>', 3)->where('rank', '<=', 20)
            ->update(['song_id' => null]);

        $room = $this->iconicRoom($artist);
        $svc = app(RoundService::class);

        $ranks = [];
        for ($i = 0; $i < 5; $i++) {
            $ranks[] = $this->rankOf($artist, $svc->startNextRound($room->fresh())->song_id);
        }

        // Rounds 1-3 exhaust the three playable top-20 tracks (order among the
        // top few is randomized by design), then it spills to the reservoir.
        $first3 = array_slice($ranks, 0, 3);
        sort($first3);
        $this->assertSame([1, 2, 3], $first3);
        $this->assertGreaterThan(20, $ranks[3]);
        $this->assertGreaterThan(20, $ranks[4]);
    }

    public function test_it_repeats_rather_than_throwing_when_everything_is_used(): void
    {
        $artist = IconicArtist::factory()->fetched(40)->create();
        IconicArtistSong::where('iconic_artist_id', $artist->id)
            ->where('rank', '>', 2)->update(['song_id' => null]);

        $room = $this->iconicRoom($artist);
        $svc = app(RoundService::class);

        $songIds = [];
        for ($i = 0; $i < 4; $i++) {
            $songIds[] = $svc->startNextRound($room->fresh())->song_id;
        }

        $this->assertCount(4, $songIds);          // never threw
        $this->assertLessThanOrEqual(2, count(array_unique($songIds)));
    }

    public function test_zero_playable_songs_throws_a_friendly_error(): void
    {
        $artist = IconicArtist::factory()->fetched(10)->create(['name' => 'Queen']);
        IconicArtistSong::where('iconic_artist_id', $artist->id)->update(['song_id' => null]);

        $room = $this->iconicRoom($artist);
        $svc = app(RoundService::class);

        try {
            $svc->startNextRound($room->fresh());
            $this->fail('expected a RuntimeException');
        } catch (RuntimeException $e) {
            $this->assertMatchesRegularExpression('/Still fetching .*Queen.* songs/', $e->getMessage());
        }

        // start() throws the same and leaves the room in the lobby.
        $room->update(['status' => 'lobby']);
        try {
            $svc->start($room->fresh());
            $this->fail('expected start() to throw');
        } catch (RuntimeException) {
            $this->assertSame('lobby', $room->fresh()->status->value);
        }
    }
}
