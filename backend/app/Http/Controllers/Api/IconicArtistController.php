<?php

namespace App\Http\Controllers\Api;

use App\Enums\DifficultyTier;
use App\Enums\GameMode;
use App\Enums\RoomPlayerMode;
use App\Enums\RoomStatus;
use App\Enums\SongGenre;
use App\Http\Controllers\Controller;
use App\Models\GameRoom;
use App\Models\IconicArtist;
use App\Models\RoomPlayer;
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
    /**
     * GET /api/iconic-artists - the landing-page carousel. Only free artists
     * (price = 0) and ones this user has unlocked in the Shop appear here;
     * priced-but-unowned acts are discoverable on the Shop page. Reachable by
     * a guest (guest-ok group) - a guest owns nothing, so they see only the
     * free ones, and picking any still needs an account (start() below).
     */
    public function index(Request $request)
    {
        $ownedIds = $request->user()?->iconicArtists()->pluck('iconic_artists.id')->all() ?? [];

        return response()->json(
            IconicArtist::query()
                ->where('enabled', true)
                ->where(fn ($q) => $q->where('price', 0)->orWhereIn('id', $ownedIds))
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

        // The iconic series is an account feature (the route's `not-guest`
        // gate already enforces this; explicit here too).
        abort_if($request->user()->is_guest, 403, 'Create a free account to play the Iconic Artist series.');

        // Priced acts must be unlocked in the Shop first; free acts (price 0)
        // are open to any account.
        if ($iconicArtist->price > 0
            && ! $request->user()->iconicArtists()->whereKey($iconicArtist->id)->exists()) {
            throw ValidationException::withMessages([
                'iconic' => ['Unlock this artist in the Shop first.'],
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

        // The iconic pool is owned (iconic_artist_songs), fetched when the
        // artist was added. Re-kick that fetch only if it never succeeded or
        // has gone stale/empty (e.g. songs:sync --fresh nulled the links).
        $stale = $iconicArtist->fetch_status === 'done'
            && ($iconicArtist->fetched_at?->lt(now()->subDays(30)) || $iconicArtist->playableTopCount() === 0);

        if (in_array($iconicArtist->fetch_status, ['pending', 'failed'], true) || $stale) {
            $iconicArtist->startCatalogueFetch();
        }

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
