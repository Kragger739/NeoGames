<?php

namespace App\Events\Ddf;

use App\Models\GameRoom;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PresenceChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Support\Collection;

class DdfVotingResults implements ShouldBroadcastNow
{
    use Dispatchable, InteractsWithSockets;

    /** @param array<int, int> $tiedPlayerIds
     *  @param Collection<int, array{room_player_id: int, vote_count: int}> $results */
    public function __construct(
        public GameRoom $room,
        public bool $isTie,
        public ?string $resolvedBy,
        public ?int $loserRoomPlayerId,
        public array $tiedPlayerIds,
        public bool $awaitingGm,
        public Collection $results,
    ) {}

    public function broadcastOn(): array
    {
        return [new PresenceChannel("room.{$this->room->code}")];
    }

    public function broadcastAs(): string
    {
        return 'ddf.voting_results';
    }

    public function broadcastWith(): array
    {
        return [
            'is_tie' => $this->isTie,
            'resolved_by' => $this->resolvedBy,
            'loser_room_player_id' => $this->loserRoomPlayerId,
            'tied_player_ids' => $this->tiedPlayerIds,
            'awaiting_gm' => $this->awaitingGm,
            'results' => $this->results,
            'server_time' => now()->toIso8601String(),
        ];
    }
}
