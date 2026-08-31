<?php

namespace App\Http\Controllers\Api;

use App\Enums\DifficultyTier;
use App\Enums\GameMode;
use App\Enums\RoomPlayerMode;
use App\Enums\RoomStatus;
use App\Enums\SongGenre;
use App\Http\Controllers\Controller;
use App\Models\DailyChallenge;
use App\Models\DailyChallengeAttempt;
use App\Models\GameRoom;
use App\Models\RoomPlayer;
use App\Services\RoundService;
use Illuminate\Database\QueryException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use RuntimeException;

/**
 * The Daily challenge: a solo, Classic-style, five-round game whose songs
 * are fixed for the day. One attempt per player per day. Unlike a normal
 * game night this has no lobby and no level gate - it's the always-open
 * onboarding path.
 */
class DailyChallengeController extends Controller
{
    /** GET /api/daily - today's status for the current user. */
    public function show(Request $request)
    {
        $challenge = DailyChallenge::forDate(now());
        $attempt = DailyChallengeAttempt::query()
            ->where('daily_challenge_id', $challenge->id)
            ->where('user_id', $request->user()->id)
            ->first();

        return response()->json([
            'date' => $challenge->date->toDateString(),
            'played' => $attempt !== null,
            'finished' => $attempt?->finished_at !== null,
            'best_score' => $attempt?->score,
        ]);
    }

    /** POST /api/daily/start - create the solo room, start it, drop in. */
    public function start(Request $request, RoundService $roundService)
    {
        $user = $request->user();
        $challenge = DailyChallenge::forDate(now());

        if (DailyChallengeAttempt::where('daily_challenge_id', $challenge->id)->where('user_id', $user->id)->exists()) {
            throw ValidationException::withMessages([
                'daily' => ["You've already played today's daily — come back tomorrow."],
            ]);
        }

        try {
            [$room, $hostPlayer] = DB::transaction(function () use ($user, $challenge) {
                $room = $user->rooms()->create([
                    'code' => GameRoom::generateUniqueCode(),
                    'status' => RoomStatus::Lobby->value,
                    'mode' => GameMode::Classic->value,
                    'player_mode' => RoomPlayerMode::Solo->value,
                    'genre' => SongGenre::Iconic->value,
                    'songs_per_tier' => DailyChallenge::SONG_COUNT,
                    'enabled_tiers' => [DifficultyTier::Easy->value],
                    'guess_timeout_seconds' => 8,
                    'current_tier' => DifficultyTier::Easy->value,
                    'daily_challenge_id' => $challenge->id,
                ]);

                $hostPlayer = $room->players()->create([
                    'user_id' => $user->id,
                    'nickname' => mb_substr($user->username ?? $user->name, 0, 20),
                    'connection_token' => RoomPlayer::generateConnectionToken(),
                    'score' => 0,
                ]);

                // UNIQUE(daily_challenge_id, user_id) makes a double-start race
                // a QueryException rather than a second room.
                DailyChallengeAttempt::create([
                    'daily_challenge_id' => $challenge->id,
                    'user_id' => $user->id,
                    'room_id' => $room->id,
                ]);

                return [$room, $hostPlayer];
            });
        } catch (QueryException) {
            throw ValidationException::withMessages([
                'daily' => ["You've already played today's daily — come back tomorrow."],
            ]);
        }

        try {
            $roundService->start($room);
        } catch (RuntimeException $e) {
            throw ValidationException::withMessages(['daily' => [$e->getMessage()]]);
        }

        return response()->json([
            'code' => $room->code,
            'player' => [
                'id' => $hostPlayer->id,
                'connection_token' => $hostPlayer->connection_token,
                'nickname' => $hostPlayer->nickname,
            ],
        ], 201);
    }
}
