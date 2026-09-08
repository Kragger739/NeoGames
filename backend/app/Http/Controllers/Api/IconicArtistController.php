<?php

namespace App\Http\Controllers\Api;

use App\Enums\DifficultyTier;
use App\Enums\GameMode;
use App\Enums\RoomPlayerMode;
use App\Enums\RoomStatus;
use App\Enums\SongGenre;
use App\Http\Controllers\Controller;
use App\Jobs\PrimeArtistSongPool;
use App\Models\GameRoom;
use App\Models\IconicArtist;
use App\Models\RoomPlayer;
use App\Models\UnlockRequirement;
use App\Support\SongFilter;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

/**
 * The Iconic Artist series: a curated act picked from the landing-page
 * carousel spins up a genre=artist room and drops the player into a trimmed
 * lobby (mode + round count + auto-extend) rather than starting immediately
 * like the Daily does.
 */
class IconicArtistController extends Controller
{
    /** GET /api/iconic-artists - the landing-page carousel. */
    public function index()
    {
        return response()->json(
            IconicArtist::query()
                ->where('enabled', true)
                ->orderBy('sort_order')
                ->orderBy('id')
                ->get()
                ->map(fn (IconicArtist $artist) => [
                    'id' => $artist->id,
                    'name' => $artist->name,
                    'image_url' => $artist->image_url,
                ]),
        );
    }

    /** POST /api/iconic-artists/{iconicArtist}/start */
    public function start(Request $request, IconicArtist $iconicArtist)
    {
        // Route-model binding finds disabled rows too - a stale direct hit
        // should read as "gone", not silently create a room.
        abort_unless($iconicArtist->enabled, 404);

        $required = UnlockRequirement::levelFor('iconic_series');
        $hostLevel = (int) ($request->user()->level ?? 1);

        if ($hostLevel < $required) {
            throw ValidationException::withMessages([
                'iconic' => ["The Iconic Artist series unlocks at level {$required} (you're level {$hostLevel})."],
            ]);
        }

        $room = $request->user()->rooms()->create([
            'code' => GameRoom::generateUniqueCode(),
            // Deliberately NOT started - the trimmed lobby configures it first.
            'status' => RoomStatus::Lobby->value,
            'mode' => GameMode::Custom->value,
            'player_mode' => RoomPlayerMode::Solo->value,
            'genre' => SongGenre::Artist->value,
            'artist_name' => $iconicArtist->name,
            'songs_per_tier' => 5,
            // A single tier => the artist's whole catalogue is one flat pool,
            // no within-artist difficulty ramp.
            'enabled_tiers' => [DifficultyTier::Easy->value],
            'guess_timeout_seconds' => 8,
            'current_tier' => DifficultyTier::Easy->value,
            'iconic_artist_id' => $iconicArtist->id,
        ]);

        // Warm the artist's pool while the player picks a mode. RoundService::start()
        // still has its synchronous ensureArtistPoolReady() safety net.
        PrimeArtistSongPool::dispatch(SongFilter::fromRoom($room));

        $hostPlayer = $room->players()->create([
            'user_id' => $request->user()->id,
            'nickname' => mb_substr($request->user()->username ?? $request->user()->name, 0, 20),
            'connection_token' => RoomPlayer::generateConnectionToken(),
            'score' => 0,
        ]);

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
