<?php

namespace App\Events;

use App\Models\RoomPlayer;
use App\Models\Round;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PresenceChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Support\Collection;

/**
 * Battle Royale's round-close signal: unlike RoundWon (a single winner),
 * a BR round can close with any number of survivors and eliminated players,
 * so it gets its own event rather than overloading RoundWon's shape.
 */
class BattleRoyaleRoundResolved implements ShouldBroadcastNow
{
    use Dispatchable, InteractsWithSockets;

    /**
     * @param  Collection<int, RoomPlayer>  $survivors  selectForSummary()'d - carries `level`
     * @param  Collection<int, RoomPlayer>  $eliminated  selectForSummary()'d - carries `level`
     */
    public function __construct(
        public Round $round,
        public Collection $survivors,
        public Collection $eliminated,
    ) {}

    public function broadcastOn(): array
    {
        return [new PresenceChannel("room.{$this->round->room->code}")];
    }

    public function broadcastAs(): string
    {
        return 'round.br_resolved';
    }

    public function broadcastWith(): array
    {
        return [
            'round_id' => $this->round->id,
            'survivors' => $this->survivors->values(),
            'eliminated' => $this->eliminated->values(),
            'answer' => [
                'title' => $this->round->song->title,
                'artist' => $this->round->song->artist,
                'album_art_url' => $this->round->song->album_art_url,
                'artist_follower_count' => $this->round->song->artist_follower_count,
                'provider_track_id' => $this->round->song->provider_track_id,
            ],
            'scoreboard' => $this->round->room->players()
                ->orderByDesc('score')
                ->selectForSummary()
                ->get(),
        ];
    }
}
