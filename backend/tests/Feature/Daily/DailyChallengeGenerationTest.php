<?php

namespace Tests\Feature\Daily;

use App\Models\DailyChallenge;
use App\Models\Song;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class DailyChallengeGenerationTest extends TestCase
{
    use RefreshDatabase;

    public function test_generation_is_deterministic_for_a_date(): void
    {
        Song::factory()->count(20)->create(['genre' => 'iconic']);
        $day = Carbon::parse('2026-08-31');

        $first = DailyChallenge::forDate($day)->song_ids;
        DailyChallenge::query()->delete();
        $second = DailyChallenge::forDate($day)->song_ids;

        $this->assertCount(DailyChallenge::SONG_COUNT, $first);
        $this->assertSame($first, $second);
    }

    public function test_different_dates_get_different_sets(): void
    {
        Song::factory()->count(30)->create(['genre' => 'iconic']);

        $a = DailyChallenge::forDate(Carbon::parse('2026-08-31'))->song_ids;
        $b = DailyChallenge::forDate(Carbon::parse('2026-09-01'))->song_ids;

        $this->assertNotSame($a, $b);
    }

    public function test_for_date_is_idempotent_and_stores_one_row(): void
    {
        Song::factory()->count(10)->create(['genre' => 'iconic']);
        $day = Carbon::parse('2026-08-31');

        $one = DailyChallenge::forDate($day);
        $two = DailyChallenge::forDate($day);

        $this->assertSame($one->id, $two->id);
        $this->assertSame($one->song_ids, $two->song_ids);
        $this->assertDatabaseCount('daily_challenges', 1);
    }

    public function test_it_draws_only_from_the_iconic_pool_when_large_enough(): void
    {
        $iconic = Song::factory()->count(10)->create(['genre' => 'iconic'])->pluck('id')->all();
        Song::factory()->count(10)->create(['genre' => 'pop']);

        $challenge = DailyChallenge::forDate(now());

        $this->assertSame([], array_diff($challenge->song_ids, $iconic));
        $this->assertCount(DailyChallenge::SONG_COUNT, $challenge->song_ids);
    }

    public function test_it_widens_to_the_whole_pool_when_iconic_is_too_small(): void
    {
        Song::factory()->count(3)->create(['genre' => 'iconic']);
        Song::factory()->count(10)->create(['genre' => 'pop']);

        $challenge = DailyChallenge::forDate(now());

        $this->assertCount(DailyChallenge::SONG_COUNT, $challenge->song_ids);
    }

    public function test_songs_are_returned_in_stored_order(): void
    {
        Song::factory()->count(12)->create(['genre' => 'iconic']);
        $challenge = DailyChallenge::forDate(now());

        $this->assertSame($challenge->song_ids, $challenge->songs()->pluck('id')->all());
    }
}
