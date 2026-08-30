<?php

namespace App\Events\Ddf;

use App\Models\DdfGame;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PresenceChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;

class DdfGameStarted implements ShouldBroadcastNow
{
    use Dispatchable, InteractsWithSockets;

    public function __construct(public DdfGame $game) {}

    public function broadcastOn(): array
    {
        return [new PresenceChannel("room.{$this->game->room->code}")];
    }

    public function broadcastAs(): string
    {
        return 'ddf.game_started';
    }

    public function broadcastWith(): array
    {
        return [
            'state' => $this->game->state->value,
            'rounds_per_voting' => $this->game->rounds_per_voting,
            'question_timer_seconds' => $this->game->question_timer_seconds,
            'voting_timer_seconds' => $this->game->voting_timer_seconds,
            'language' => $this->game->language->value,
            'couch_mode' => $this->game->couch_mode,
            'players' => $this->game->room->players()
                ->selectForDdfSummary()
                ->get()
                ->map(fn ($p) => [
                    'room_player_id' => $p->id,
                    'nickname' => $p->nickname,
                    'hearts' => $p->ddfState->hearts,
                    'is_eliminated' => $p->ddfState->is_eliminated,
                ]),
            'server_time' => now()->toIso8601String(),
        ];
    }
}
