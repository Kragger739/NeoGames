<?php

namespace Tests\Feature\Daily;

use App\Jobs\AdvanceRoundStage;
use App\Jobs\ExpandSongPool;
use App\Jobs\FinishGame;
use App\Jobs\StartNextRound;
use App\Models\GameRoom;
use App\Models\Song;
use App\Models\UnlockRequirement;
use App\Models\User;
use App\Services\RoundService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class DailyStartTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Queue::fake([AdvanceRoundStage::class, ExpandSongPool::class, StartNextRound::class, FinishGame::class]);
        $this->fakeDeezerTrackRefresh();
    }

    private function seedIconic(int $n = 12): void
    {
        Song::factory()->count($n)->create(['genre' => 'iconic']);
    }

    public function test_it_starts_a_solo_five_round_game_and_returns_a_join_token(): void
    {
        Event::fake();
        $this->seedIconic();
        $host = User::factory()->create(['xp' => 0]);

        $res = $this->actingAs($host)->postJson('/api/daily/start')->assertCreated();

        $code = $res->json('code');
        $this->assertNotNull($code);
        $this->assertNotNull($res->json('player.connection_token'));

        $room = GameRoom::where('code', $code)->first();
        $this->assertSame('active', $room->status->value);
        $this->assertSame('solo', $room->player_mode->value);
        $this->assertSame(5, $room->songs_per_tier);
        $this->assertNotNull($room->daily_challenge_id);
        $this->assertDatabaseHas('room_players', ['room_id' => $room->id, 'user_id' => $host->id]);
        $this->assertDatabaseHas('daily_challenge_attempts', ['user_id' => $host->id, 'room_id' => $room->id]);
    }

    public function test_the_rounds_play_the_challenge_songs_in_order(): void
    {
        Event::fake();
        $this->seedIconic();
        $host = User::factory()->create();

        $this->actingAs($host)->postJson('/api/daily/start')->assertCreated();

        $room = GameRoom::where('host_id', $host->id)->first();
        $challenge = $room->dailyChallenge;

        $this->assertSame($challenge->song_ids[0], $room->rounds()->latest('id')->first()->song_id);

        $svc = app(RoundService::class);
        for ($i = 1; $i < 5; $i++) {
            $room->update(['current_song_index' => $i]);
            $round = $svc->startNextRound($room->fresh());
            $this->assertSame($challenge->song_ids[$i], $round->song_id);
        }
    }

    public function test_only_one_attempt_per_day(): void
    {
        Event::fake();
        $this->seedIconic();
        $host = User::factory()->create();

        $this->actingAs($host)->postJson('/api/daily/start')->assertCreated();
        $this->actingAs($host)->postJson('/api/daily/start')
            ->assertUnprocessable()
            ->assertJsonValidationErrors('daily');
    }

    public function test_a_new_day_is_a_fresh_attempt(): void
    {
        Event::fake();
        $this->seedIconic();
        $host = User::factory()->create();

        $this->actingAs($host)->postJson('/api/daily/start')->assertCreated();

        $this->travel(1)->days();

        $this->actingAs($host)->postJson('/api/daily/start')->assertCreated();
        $this->assertDatabaseCount('daily_challenges', 2);
    }

    public function test_the_daily_is_never_level_gated(): void
    {
        Event::fake();
        $this->seedIconic();
        UnlockRequirement::updateOrCreate(['key' => 'game_night'], ['required_level' => 50]);
        $host = User::factory()->create(['xp' => 0]);

        $this->actingAs($host)->postJson('/api/daily/start')->assertCreated();
    }

    public function test_show_reports_played_and_finished_state(): void
    {
        Event::fake();
        $this->seedIconic();
        $host = User::factory()->create();

        $this->actingAs($host)->getJson('/api/daily')
            ->assertOk()
            ->assertJsonPath('played', false);

        $this->actingAs($host)->postJson('/api/daily/start')->assertCreated();

        $this->actingAs($host)->getJson('/api/daily')
            ->assertJsonPath('played', true)
            ->assertJsonPath('finished', false);
    }
}
