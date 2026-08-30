<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\Music\SpotifyClient;
use Illuminate\Http\Request;

/**
 * Live search for the guess-box autocomplete. Deliberately searches Spotify
 * directly rather than the room's song pool, so results never hint at which
 * songs are actually in play this game. Only title / artist / art are
 * needed here - no preview audio (the guess box never plays anything).
 */
class SongSearchController extends Controller
{
    public function search(Request $request, SpotifyClient $spotify)
    {
        $request->validate([
            'q' => ['required', 'string', 'min:2', 'max:100'],
        ]);

        $results = $spotify->searchTrack($request->string('q')->toString(), limit: 8);

        return response()->json([
            'results' => array_values(array_map(fn (array $track) => [
                'provider_track_id' => $track['provider_track_id'],
                'title' => $track['title'],
                'artist' => $track['artist'],
                'album_art_url' => $track['album_art_url'],
            ], $results)),
        ]);
    }
}
