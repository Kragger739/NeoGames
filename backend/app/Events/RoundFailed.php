<?php

namespace App\Events;

use App\Models\Round;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PresenceChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;

class RoundFailed implements ShouldBroadcastNow
{
    use Dispatchable, InteractsWithSockets;

    public function __construct(public Round $round) {}

    public function broadcastOn(): array
    {
        return [new PresenceChannel("room.{$this->round->room->code}")];
    }

    public function broadcastAs(): string
    {
        return 'round.failed';
    }

    public function broadcastWith(): array
    {
        return [
            'round_id' => $this->round->id,
            'answer' => [
                'title' => $this->round->song->title,
                'artist' => $this->round->song->artist,
                'album_art_url' => $this->round->song->album_art_url,
                'artist_fan_count' => $this->round->song->artist_fan_count,
                'deezer_track_id' => $this->round->song->deezer_track_id,
            ],
        ];
    }
}
