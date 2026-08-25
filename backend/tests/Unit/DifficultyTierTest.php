<?php

namespace Tests\Unit;

use App\Enums\DifficultyTier;
use PHPUnit\Framework\TestCase;

class DifficultyTierTest extends TestCase
{
    public function test_popularity_ranges_cover_the_floor_to_100_with_no_gaps_or_overlaps(): void
    {
        $covered = array_fill(DifficultyTier::MIN_POPULARITY, 101 - DifficultyTier::MIN_POPULARITY, 0);

        foreach (DifficultyTier::ordered() as $tier) {
            [$min, $max] = $tier->popularityRange();
            for ($p = $min; $p <= $max; $p++) {
                $covered[$p]++;
            }
        }

        foreach ($covered as $popularity => $count) {
            $this->assertSame(1, $count, "popularity {$popularity} should be covered by exactly one tier");
        }
    }

    public function test_popularity_below_the_floor_matches_no_tier(): void
    {
        $this->assertNull(DifficultyTier::fromPopularity(DifficultyTier::MIN_POPULARITY - 1));
        $this->assertNull(DifficultyTier::fromPopularity(0));
    }

    public function test_fromPopularity_maps_known_values_to_the_expected_tier(): void
    {
        $this->assertSame(DifficultyTier::Extreme, DifficultyTier::fromPopularity(DifficultyTier::MIN_POPULARITY));
        $this->assertSame(DifficultyTier::Hard, DifficultyTier::fromPopularity(50));
        $this->assertSame(DifficultyTier::Medium, DifficultyTier::fromPopularity(65));
        $this->assertSame(DifficultyTier::Intermediate, DifficultyTier::fromPopularity(75));
        $this->assertSame(DifficultyTier::Easy, DifficultyTier::fromPopularity(100));
    }
}
