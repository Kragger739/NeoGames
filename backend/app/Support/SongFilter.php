<?php

namespace App\Support;

use App\Enums\DifficultyTier;
use App\Enums\SongGenre;
use App\Models\GameRoom;

/**
 * Bundles everything song discovery/selection needs to know about a room's
 * settings, in place of a bare DifficultyTier - replaces that parameter
 * everywhere it used to be threaded through SongDiscoveryService/
 * ExpandSongPool.
 */
final class SongFilter
{
    public function __construct(
        public readonly DifficultyTier $tier,
        public readonly SongGenre $genre = SongGenre::Normal,
        public readonly ?int $yearFrom = null,
        public readonly ?int $yearTo = null,
        public readonly ?string $artistName = null,
        public readonly ?array $artistNames = null,
        // The room's enabled tiers (Part A) - [] means "use
        // DifficultyTier::ordered()", needed by the Artist/MultiArtist
        // relative-ranking bucket math in SongDiscoveryService.
        public readonly array $enabledTiers = [],
        // A custom Workshop dataset id. When set, genre/year/artist are all
        // ignored: SongDiscoveryService picks straight from dataset_tracks.
        public readonly ?int $datasetId = null,
    ) {}

    public static function fromRoom(GameRoom $room): self
    {
        return new self(
            tier: $room->current_tier,
            genre: $room->genre,
            yearFrom: $room->year_from,
            yearTo: $room->year_to,
            artistName: $room->artist_name,
            artistNames: $room->artist_names,
            enabledTiers: $room->enabledTiers(),
            datasetId: $room->dataset_id,
        );
    }

    /**
     * Stable identifier for ExpandSongPool's lock key. Year mode's range and
     * Artist/MultiArtist's name(s) are all arbitrary per-room (not one of a
     * handful of fixed values), so each gets its own effectively-per-room
     * key rather than sharing one with every other Year/Artist/MultiArtist
     * room.
     */
    public function cacheKey(): string
    {
        if ($this->datasetId !== null) {
            return "dataset:{$this->datasetId}:{$this->tier->value}";
        }

        $genrePart = match ($this->genre) {
            SongGenre::Year => "year:{$this->yearFrom}-{$this->yearTo}",
            SongGenre::Artist => "artist:{$this->artistName}",
            SongGenre::MultiArtist => 'multi_artist:'.implode(',', collect($this->artistNames ?? [])
                ->map(fn ($name) => mb_strtolower(trim($name)))
                ->sort()
                ->values()
                ->all()),
            default => $this->genre->value,
        };

        return "{$genrePart}:{$this->tier->value}";
    }
}
