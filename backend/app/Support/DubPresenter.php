<?php

namespace App\Support;

use App\Models\DubClip;
use App\Models\DubGame;
use App\Models\GameRoom;
use App\Models\RoomPlayer;
use Illuminate\Support\Facades\Storage;

/**
 * Shared serialization for "Dub Together" - the one place clip, roster,
 * assignment and current-line shapes are built, reused by the broadcast
 * events and by DubGameController::present() so a receiving client applies
 * a live event and a catch-up GET identically.
 */
class DubPresenter
{
    public static function clip(?DubClip $clip): ?array
    {
        if (! $clip) {
            return null;
        }

        $clip->loadMissing(['characters', 'lines']);

        return [
            'id' => $clip->id,
            'title' => $clip->title,
            'status' => $clip->status,
            'duration_ms' => $clip->duration_ms,
            'video_url' => self::url($clip->source_video_path),
            'characters' => $clip->characters->map(fn ($c) => [
                'id' => $c->id,
                'display_name' => $c->display_name,
                'color' => $c->color,
                'position' => $c->position,
            ])->values(),
            'lines' => $clip->lines->map(fn ($l) => [
                'id' => $l->id,
                'position' => $l->position,
                'character_id' => $l->dub_clip_character_id,
                'start_ms' => $l->start_ms,
                'end_ms' => $l->end_ms,
                'text' => $l->text,
            ])->values(),
        ];
    }

    /** @return array<int, array<string, mixed>> */
    public static function assignments(DubGame $game): array
    {
        return $game->roleAssignments()
            ->where('round_number', $game->round_number)
            ->get()
            ->map(fn ($a) => [
                'character_id' => $a->dub_clip_character_id,
                'room_player_id' => $a->room_player_id,
            ])
            ->values()
            ->all();
    }

    /** @return array<int, array<string, mixed>> */
    public static function players(GameRoom $room): array
    {
        $game = $room->dubGame;
        $assignments = $game
            ? collect(self::assignments($game))->keyBy('room_player_id')
            : collect();

        return $room->players()
            ->with(['dubState', 'user:id,xp,avatar_path,equipped_cosmetics,is_admin'])
            ->select(['id', 'nickname', 'user_id'])
            ->get()
            ->map(fn (RoomPlayer $p) => [
                'room_player_id' => $p->id,
                'nickname' => $p->nickname,
                'level' => $p->level,
                'avatar' => $p->avatar,
                'is_host' => $p->user_id !== null && $p->user_id === $room->host_id,
                'mic_ready' => (bool) $p->dubState?->mic_ready,
                'claimed_character_id' => $assignments->get($p->id)['character_id'] ?? null,
            ])
            ->values()
            ->all();
    }

    /**
     * The line the recording phase is currently on, plus who is assigned to
     * voice it this round - null outside Recording or when the clip has no
     * such line.
     *
     * @return array{line: array<string, mixed>, assigned_room_player_id: int|null}|null
     */
    public static function currentLine(DubGame $game): ?array
    {
        $clip = $game->clip;

        if (! $clip) {
            return null;
        }

        $line = $clip->lines()->where('position', $game->current_line_index)->first();

        if (! $line) {
            return null;
        }

        $assignment = $game->roleAssignments()
            ->where('round_number', $game->round_number)
            ->where('dub_clip_character_id', $line->dub_clip_character_id)
            ->first();

        return [
            'line' => [
                'id' => $line->id,
                'position' => $line->position,
                'character_id' => $line->dub_clip_character_id,
                'start_ms' => $line->start_ms,
                'end_ms' => $line->end_ms,
                'text' => $line->text,
            ],
            'assigned_room_player_id' => $assignment?->room_player_id,
        ];
    }

    public static function url(?string $path): ?string
    {
        if (! $path) {
            return null;
        }

        return Storage::disk('public')->url($path);
    }
}
