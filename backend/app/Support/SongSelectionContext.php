<?php

namespace App\Support;

use App\Enums\SongEra;

/**
 * A room's game-session state that song selection needs beyond its
 * settings (SongFilter) - which songs/artists have already been used this
 * game, and the realized era mix so far, so SongDiscoveryService can bias
 * toward variety and the target era mix without ever making either a hard
 * filter that could leave a round with nothing to play.
 */
final class SongSelectionContext
{
    /**
     * @param  array<int, string>  $excludeTrackIds  provider_track_ids already used this game
     * @param  array<int, string>  $usedArtistProviderIds  artist provider ids already used this game
     * @param  array<string, int>  $eraCounts  SongEra::value => count so far this game
     */
    public function __construct(
        public readonly array $excludeTrackIds = [],
        public readonly array $usedArtistProviderIds = [],
        public readonly array $eraCounts = [],
    ) {}

    public static function empty(): self
    {
        return new self;
    }

    /** Immutable - used when a candidate turns out to be unplayable and a fresh pick is needed without it. */
    public function withExcludedTrack(string $providerTrackId): self
    {
        return new self(
            [...$this->excludeTrackIds, $providerTrackId],
            $this->usedArtistProviderIds,
            $this->eraCounts,
        );
    }

    private function totalPlayed(): int
    {
        return array_sum($this->eraCounts);
    }

    /**
     * Deficit scheduling: picks whichever era is furthest behind its target
     * share of the game so far, so the mix converges toward
     * config('songs.era_*_share') over the course of a full game rather
     * than needing to hit the ratio within any single tier's handful of
     * songs. Null on the very first pick (nothing played yet) - there's no
     * meaningful deficit to compute from zero history, and forcing a guess
     * (Mainstream, the largest target share) would divert even a fresh
     * room's first chart-sourced tier away from real chart discovery for no
     * reason (confirmed live - chart-sourced tiers structurally can't
     * supply anything but Current-era candidates, so targeting anything
     * else pushes discovery to a word search instead; that diversion should
     * only happen once there's an actual mix to correct toward).
     */
    public function neediestEra(): ?SongEra
    {
        $totalPlayed = $this->totalPlayed();

        if ($totalPlayed === 0) {
            return null;
        }

        return collect(SongEra::cases())
            ->sortByDesc(function (SongEra $era) use ($totalPlayed) {
                $actual = $this->eraCounts[$era->value] ?? 0;

                return $era->targetShare() - ($actual / $totalPlayed);
            })
            ->first();
    }
}
