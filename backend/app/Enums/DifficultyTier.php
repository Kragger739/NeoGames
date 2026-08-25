<?php

namespace App\Enums;

enum DifficultyTier: string
{
    case Easy = 'easy';
    case Intermediate = 'intermediate';
    case Medium = 'medium';
    case Hard = 'hard';
    case Extreme = 'extreme';

    /**
     * Fixed progression order the game advances through.
     *
     * @return array<int, self>
     */
    public static function ordered(): array
    {
        return [self::Easy, self::Intermediate, self::Medium, self::Hard, self::Extreme];
    }

    public function next(): ?self
    {
        $order = self::ordered();
        $index = array_search($this, $order, strict: true);

        return $order[$index + 1] ?? null;
    }

    /**
     * Popularity floor below which a track is excluded entirely (too
     * obscure even for Extreme) - Spotify's 0-100 "popularity" score is
     * based on play volume/recency, so higher = more widely known.
     */
    public const MIN_POPULARITY = 35;

    /**
     * Inclusive [min, max] popularity range per tier. Spans the whole
     * MIN_POPULARITY-100 range with no gaps, easiest tier first.
     *
     * @return array<int, int>
     */
    public function popularityRange(): array
    {
        return match ($this) {
            self::Easy => [85, 100],
            self::Intermediate => [72, 84],
            self::Medium => [60, 71],
            self::Hard => [48, 59],
            self::Extreme => [self::MIN_POPULARITY, 47],
        };
    }

    public static function fromPopularity(int $popularity): ?self
    {
        foreach (self::ordered() as $tier) {
            [$min, $max] = $tier->popularityRange();

            if ($popularity >= $min && $popularity <= $max) {
                return $tier;
            }
        }

        return null;
    }
}
