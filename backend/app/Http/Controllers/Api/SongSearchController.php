<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Song;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;

/**
 * Live search for the guess-box autocomplete. Matches against the local
 * `songs` pool (the Spotify search API is 403-blocked for app tokens), most
 * recognizable first. Only title / artist / art are returned - the guess
 * box never plays audio.
 */
class SongSearchController extends Controller
{
    public function search(Request $request)
    {
        $request->validate([
            'q' => ['required', 'string', 'min:2', 'max:100'],
        ]);

        $term = trim((string) $request->query('q'));
        // Lowercase + LOWER() so matching is case-insensitive on every driver -
        // Postgres LIKE is case-sensitive (unlike sqlite/mysql), so a plain
        // '%blinding%' would never match "Blinding Lights". Escape LIKE
        // wildcards so a literal % or _ in the query isn't treated as one.
        $like = '%'.addcslashes(mb_strtolower($term), '%_\\').'%';

        $results = Song::query()
            ->where('excluded', false)
            ->where(fn (Builder $q) => $q
                ->whereRaw('LOWER(title) LIKE ?', [$like])
                ->orWhereRaw('LOWER(artist) LIKE ?', [$like]))
            ->orderByDesc('popularity')
            ->limit(8)
            ->get(['provider_track_id', 'title', 'artist', 'album_art_url']);

        return response()->json([
            'results' => $results->map(fn (Song $song) => [
                'provider_track_id' => $song->provider_track_id,
                'title' => $song->title,
                'artist' => $song->artist,
                'album_art_url' => $song->album_art_url,
            ])->values(),
        ]);
    }
}
