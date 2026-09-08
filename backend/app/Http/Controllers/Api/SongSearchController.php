<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Song;
use App\Support\GuessNormalizer;
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
        // Fold the query the same way a guess is folded (case + the
        // punctuation players routinely drop - see GuessNormalizer), and match
        // it against the columns folded by the same rule, so "dont stop me
        // now" still turns up "Don't Stop Me Now". Escape LIKE wildcards so a
        // literal % or _ in the query isn't treated as one.
        $normalized = GuessNormalizer::normalize($term);

        if ($normalized === '') {
            // The raw query passed min:2, but stripping punctuation emptied it
            // (e.g. ".." or "!!") - a blank LIKE would match every song.
            return response()->json(['results' => []]);
        }

        $like = '%'.addcslashes($normalized, '%_\\').'%';

        $results = Song::query()
            ->where('excluded', false)
            ->where(fn (Builder $q) => $q
                ->whereRaw(GuessNormalizer::sqlExpr('title').' LIKE ?', [$like])
                ->orWhereRaw(GuessNormalizer::sqlExpr('artist').' LIKE ?', [$like]))
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
