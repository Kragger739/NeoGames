<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\Deezer\DeezerClient;
use Illuminate\Http\Request;

/**
 * Live search for the guess-box autocomplete. Deliberately searches Deezer
 * directly rather than the room's discovered song pool, so results never
 * hint at which songs are actually in play this game.
 */
class SongSearchController extends Controller
{
    public function search(Request $request, DeezerClient $deezer)
    {
        $request->validate([
            'q' => ['required', 'string', 'min:2', 'max:100'],
        ]);

        $results = $deezer->search($request->string('q')->toString(), limit: 8);

        $withPreview = array_filter($results, fn (array $track) => $track['preview_url'] !== null);

        return response()->json([
            'results' => array_values(array_map(fn (array $track) => [
                'deezer_track_id' => $track['deezer_track_id'],
                'title' => $track['title'],
                'artist' => $track['artist'],
                'album_art_url' => $track['album_art_url'],
                'preview_url' => $track['preview_url'],
            ], $withPreview)),
        ]);
    }
}
