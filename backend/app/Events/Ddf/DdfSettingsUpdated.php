<?php

namespace App\Events\Ddf;

use App\Models\DdfGame;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PresenceChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;

class DdfSettingsUpdated implements ShouldBroadcastNow
{
    use Dispatchable, InteractsWithSockets;

    public function __construct(public DdfGame $game) {}

    public function broadcastOn(): array
    {
        return [new PresenceChannel("room.{$this->game->room->code}")];
    }

    public function broadcastAs(): string
    {
        return 'ddf.settings_updated';
    }

    public function broadcastWith(): array
    {
        return [
            'rounds_per_voting' => $this->game->rounds_per_voting,
            'question_timer_seconds' => $this->game->question_timer_seconds,
            'voting_timer_seconds' => $this->game->voting_timer_seconds,
            'language' => $this->game->language->value,
            'couch_mode' => $this->game->couch_mode,
            'safe_mode' => $this->game->safe_mode,
            'dataset_id' => $this->game->dataset_id,
            'dataset_name' => $this->game->dataset?->name,
            'server_time' => now()->toIso8601String(),
        ];
    }
}
