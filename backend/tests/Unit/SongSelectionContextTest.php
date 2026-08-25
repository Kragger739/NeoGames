<?php

namespace Tests\Unit;

use App\Enums\SongEra;
use App\Support\SongSelectionContext;
use Tests\TestCase;

class SongSelectionContextTest extends TestCase
{
    public function test_the_first_pick_has_no_target_era(): void
    {
        // Nothing to compute a deficit from yet - and forcing a guess would
        // needlessly divert a fresh room's first chart-sourced tier away
        // from real chart discovery (see neediestEra()'s docblock).
        $context = SongSelectionContext::empty();

        $this->assertNull($context->neediestEra());
    }

    public function test_it_targets_whichever_era_is_furthest_behind_its_target_share(): void
    {
        // 10 songs played, all Mainstream (target 60%) and Current (target
        // 15%) - Classic (target 25%) has 0% actual share, the largest gap.
        $context = new SongSelectionContext(eraCounts: [
            'mainstream' => 8,
            'current' => 2,
            'classic' => 0,
        ]);

        $this->assertSame(SongEra::Classic, $context->neediestEra());
    }

    public function test_it_stops_targeting_an_era_once_it_reaches_its_share(): void
    {
        // Classic is already over-represented (50% vs its 25% target) -
        // Current (0% vs 15% target) should be targeted instead.
        $context = new SongSelectionContext(eraCounts: [
            'mainstream' => 5,
            'classic' => 5,
            'current' => 0,
        ]);

        $this->assertSame(SongEra::Current, $context->neediestEra());
    }

    public function test_with_excluded_track_appends_without_mutating_other_fields(): void
    {
        $original = new SongSelectionContext(
            excludeTrackIds: ['a'],
            usedArtistDeezerIds: ['artist-1'],
            eraCounts: ['mainstream' => 1],
        );

        $updated = $original->withExcludedTrack('b');

        $this->assertSame(['a'], $original->excludeTrackIds);
        $this->assertSame(['a', 'b'], $updated->excludeTrackIds);
        $this->assertSame($original->usedArtistDeezerIds, $updated->usedArtistDeezerIds);
        $this->assertSame($original->eraCounts, $updated->eraCounts);
    }
}
