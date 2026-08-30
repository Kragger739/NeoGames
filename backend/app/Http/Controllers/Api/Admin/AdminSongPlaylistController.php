<?php

namespace App\Http\Controllers\Api\Admin;

use App\Console\Commands\SyncSongsCommand;
use App\Enums\SongGenre;
use App\Http\Controllers\Controller;
use App\Models\SeedPlaylist;
use App\Models\Song;
use App\Services\Music\IncrementalSongSync;
use App\Services\Music\SpotifyClient;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * Admin CRUD for the curated Spotify playlists that seed each genre's song
 * pool, plus the browser-driven "Sync now" flow (IncrementalSongSync - no
 * queue worker involved). Under the same auth:sanctum + admin middleware
 * group as the rest of the admin dashboard.
 */
class AdminSongPlaylistController extends Controller
{
    /** Genres that seed from a fixed playlist (Artist / MultiArtist don't). */
    private function playlistGenres(): array
    {
        return array_values(array_filter(
            SongGenre::cases(),
            fn (SongGenre $g) => ! $g->isArtistSourced(),
        ));
    }

    public function index(IncrementalSongSync $sync)
    {
        return response()->json([
            'genres' => array_map(fn (SongGenre $g) => $g->value, $this->playlistGenres()),
            'playlists' => SeedPlaylist::query()->orderBy('genre')->orderBy('id')->get()
                ->map(fn (SeedPlaylist $p) => [
                    'id' => $p->id,
                    'genre' => $p->genre->value,
                    'spotify_playlist_id' => $p->spotify_playlist_id,
                    'label' => $p->label,
                ]),
            'pool_size' => Song::count(),
            'last_sync' => Cache::get(SyncSongsCommand::STATUS_CACHE_KEY),
            // Non-null while a browser-driven sync is mid-run (lets the page
            // resume the loop after a reload).
            'sync_progress' => $sync->current(),
        ]);
    }

    public function store(Request $request, SpotifyClient $spotify)
    {
        $data = $request->validate([
            'genre' => ['required', 'string', Rule::in(array_map(fn ($g) => $g->value, $this->playlistGenres()))],
            'playlist' => ['required', 'string', 'max:255'],
            'label' => ['nullable', 'string', 'max:120'],
        ]);

        try {
            $playlistId = $spotify->parsePlaylistId($data['playlist']);
        } catch (\RuntimeException) {
            throw ValidationException::withMessages([
                'playlist' => ['That doesn’t look like a Spotify playlist link or id.'],
            ]);
        }

        $playlist = SeedPlaylist::firstOrCreate(
            ['genre' => $data['genre'], 'spotify_playlist_id' => $playlistId],
            ['label' => $data['label'] ?? null],
        );

        return response()->json([
            'id' => $playlist->id,
            'genre' => $playlist->genre->value,
            'spotify_playlist_id' => $playlist->spotify_playlist_id,
            'label' => $playlist->label,
        ], 201);
    }

    public function destroy(SeedPlaylist $songPlaylist)
    {
        $songPlaylist->delete();

        return response()->noContent();
    }

    /**
     * Drives the browser-driven sync. `POST {"start": true}` begins a run
     * (optionally `"fresh": true` to wipe the pool first); a bare `POST`
     * advances it by one small slice. The client keeps calling until the
     * response `phase` is "done" or "error". No queue worker involved.
     */
    public function sync(Request $request, IncrementalSongSync $sync)
    {
        if ($request->boolean('start')) {
            return response()->json($sync->start($request->boolean('fresh')));
        }

        return response()->json($sync->step());
    }
}
