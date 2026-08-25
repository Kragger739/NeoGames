<?php

namespace Tests\Unit;

use App\Enums\SongEra;
use Tests\TestCase;

/**
 * Extends the Laravel-aware base TestCase (not plain PHPUnit, unlike
 * DifficultyTierTest) since SongEra::fromReleaseYear()/targetShare() read
 * config('songs.*'), which needs the app container booted.
 */
class SongEraTest extends TestCase
{
    public function test_a_release_year_within_the_recent_window_is_current(): void
    {
        $currentYear = (int) now()->year;
        $window = (int) config('songs.recent_years_window');

        $this->assertSame(SongEra::Current, SongEra::fromReleaseYear($currentYear));
        $this->assertSame(SongEra::Current, SongEra::fromReleaseYear($currentYear - $window));
    }

    public function test_a_release_year_past_the_classic_threshold_is_classic(): void
    {
        $currentYear = (int) now()->year;
        $threshold = (int) config('songs.classic_years_threshold');

        $this->assertSame(SongEra::Classic, SongEra::fromReleaseYear($currentYear - $threshold));
        $this->assertSame(SongEra::Classic, SongEra::fromReleaseYear($currentYear - $threshold - 20));
    }

    public function test_a_release_year_between_the_two_thresholds_is_mainstream(): void
    {
        $currentYear = (int) now()->year;
        $window = (int) config('songs.recent_years_window');
        $threshold = (int) config('songs.classic_years_threshold');

        $midpoint = $currentYear - intdiv($window + $threshold, 2);

        $this->assertSame(SongEra::Mainstream, SongEra::fromReleaseYear($midpoint));
    }

    public function test_target_shares_sum_to_one(): void
    {
        $total = array_sum(array_map(fn (SongEra $era) => $era->targetShare(), SongEra::cases()));

        $this->assertEqualsWithDelta(1.0, $total, 0.0001);
    }

    public function test_mainstream_has_the_largest_target_share(): void
    {
        $this->assertSame(
            SongEra::Mainstream,
            collect(SongEra::cases())->sortByDesc(fn (SongEra $era) => $era->targetShare())->first(),
        );
    }
}
