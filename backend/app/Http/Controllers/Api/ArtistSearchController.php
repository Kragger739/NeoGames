<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\Deezer\DeezerClient;
use Illuminate\Http\Request;

/**
 * Live search for the room-settings Artist-genre autocomplete - host-only
 * (see routes/api.php's auth:sanctum group), unlike SongSearchController's
 * player-facing guess search.
 */
class ArtistSearchController extends Controller
{
    public function search(Request $request, DeezerClient $deezer)
    {
        $request->validate([
            'q' => ['required', 'string', 'min:2', 'max:100'],
        ]);

        $results = $deezer->searchArtists($request->string('q')->toString(), limit: 8);

        return response()->json(['results' => $results]);
    }
}
