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
    ) {}

    public static function fromRoom(GameRoom $room): self
    {
        return new self($room->current_tier, $room->genre, $room->year_from, $room->year_to, $room->artist_name);
    }

    /**
     * Stable identifier for ExpandSongPool's lock key. Year mode's range and
     * Artist mode's name are both arbitrary per-room (not one of a handful
     * of fixed values), so each gets its own effectively-per-room key rather
     * than sharing one with every other Year/Artist-mode room.
     */
    public function cacheKey(): string
    {
        $genrePart = match ($this->genre) {
            SongGenre::Year => "year:{$this->yearFrom}-{$this->yearTo}",
            SongGenre::Artist => "artist:{$this->artistName}",
            default => $this->genre->value,
        };

        return "{$genrePart}:{$this->tier->value}";
    }
}
