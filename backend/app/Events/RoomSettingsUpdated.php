<?php

namespace App\Events;

use App\Models\GameRoom;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PresenceChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * Broadcast when the host edits songs-per-tier/guess-timeout/mode/genre
 * from the lobby, so every other viewer's copy of the settings stays live
 * without a manual refresh.
 */
class RoomSettingsUpdated implements ShouldBroadcastNow
{
    use Dispatchable, InteractsWithSockets;

    public function __construct(public GameRoom $room) {}

    public function broadcastOn(): array
    {
        return [new PresenceChannel("room.{$this->room->code}")];
    }

    public function broadcastAs(): string
    {
        return 'room.settings_updated';
    }

    public function broadcastWith(): array
    {
        return [
            'songs_per_tier' => $this->room->songs_per_tier,
            'enabled_tiers' => array_map(fn ($tier) => $tier->value, $this->room->enabledTiers()),
            'guess_timeout_seconds' => $this->room->guess_timeout_seconds,
            'mode' => $this->room->mode->value,
            'player_mode' => $this->room->player_mode->value,
            'genre' => $this->room->genre->value,
            'year_from' => $this->room->year_from,
            'year_to' => $this->room->year_to,
            'artist_name' => $this->room->artist_name,
            'artist_names' => $this->room->artist_names,
            'dataset_id' => $this->room->dataset_id,
            'dataset_name' => $this->room->dataset?->name,
        ];
    }
}
