<?php

namespace App\Events\Ddf;

use App\Models\DdfGame;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PresenceChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;

class DdfVotingStarted implements ShouldBroadcastNow
{
    use Dispatchable, InteractsWithSockets;

    /** @param array<int, int> $eligibleVoterIds
     *  @param array<int, int> $eligibleTargetIds */
    public function __construct(
        public DdfGame $game,
        public array $eligibleVoterIds,
        public array $eligibleTargetIds,
    ) {}

    public function broadcastOn(): array
    {
        return [new PresenceChannel("room.{$this->game->room->code}")];
    }

    public function broadcastAs(): string
    {
        return 'ddf.voting_started';
    }

    public function broadcastWith(): array
    {
        return [
            'voting_round_number' => $this->game->voting_round_number,
            'is_revote' => $this->game->is_revote,
            'tie_candidate_player_ids' => $this->game->tie_candidate_player_ids,
            'eligible_voter_ids' => $this->eligibleVoterIds,
            'eligible_target_ids' => $this->eligibleTargetIds,
            'timer_seconds' => $this->game->voting_timer_seconds,
            'server_time' => now()->toIso8601String(),
        ];
    }
}
