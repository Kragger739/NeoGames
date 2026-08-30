<?php

namespace Tests\Feature\Song;

use App\Enums\DifficultyTier;
use App\Models\Song;
use App\Services\SongDiscoveryService;
use App\Support\SongFilter;
use App\Support\SongSelectionContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Simulates a full room game's worth of song picks purely through
 * SongDiscoveryService (no HTTP/round-lifecycle involved), accumulating a
 * SongSelectionContext across picks the same way RoundService::
 * buildSelectionContext() would - proves the era-mix and artist-variety
 * algorithm actually converges/holds over a realistic sequence, not just
 * in isolated single-pick tests.
 */
class SongSelectionVarietyTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_full_games_worth_of_picks_converges_toward_the_target_era_mix_and_avoids_artist_repeats(): void
    {
        Http::fake(); // any call at all fails the test via assertNothingSent below.

        $currentYear = (int) now()->year;

        // 10 songs per era, each a distinct artist - 30 unique artists is
        // comfortably more than the 15 picks below, so a real repeat should
        // never be forced. -20 (not further back) keeps the "classic"
        // fixtures within Normal genre's own separate min_release_year
        // floor (2000) - an unrelated constraint that a too-old fixture
        // would fail regardless of era classification.
        foreach (['classic' => $currentYear - 20, 'mainstream' => $currentYear - 8, 'current' => $currentYear] as $era => $year) {
            for ($i = 0; $i < 10; $i++) {
                Song::factory()->forTier(DifficultyTier::Easy)->create([
                    'release_year' => $year,
                    'artist_provider_id' => "{$era}-artist-{$i}",
                ]);
            }
        }

        $service = app(SongDiscoveryService::class);
        $excludeTrackIds = [];
        $usedArtistIds = [];
        $eraCounts = [];

        for ($i = 0; $i < 15; $i++) {
            $song = $service->findRandomSongForTier(
                new SongFilter(DifficultyTier::Easy),
                new SongSelectionContext($excludeTrackIds, $usedArtistIds, $eraCounts),
            );

            $this->assertNotNull($song);
            $this->assertNotContains(
                $song->artist_provider_id,
                $usedArtistIds,
                "artist {$song->artist_provider_id} repeated before the pool of unique artists was exhausted",
            );

            $excludeTrackIds[] = $song->provider_track_id;
            $usedArtistIds[] = $song->artist_provider_id;
            $era = $song->eraBucket()->value;
            $eraCounts[$era] = ($eraCounts[$era] ?? 0) + 1;
        }

        Http::assertNothingSent();

        // Ideal split of 15 picks at 60/25/15% is ~9/3.75/2.25 - loose
        // tolerance since this is deficit scheduling over a small sample,
        // not an exact quota.
        $this->assertGreaterThanOrEqual(6, $eraCounts['mainstream'] ?? 0);
        $this->assertGreaterThanOrEqual(2, $eraCounts['classic'] ?? 0);
        $this->assertGreaterThanOrEqual(1, $eraCounts['current'] ?? 0);
    }
}
