<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Models\Song;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Browse and curate the shared song pool. "Removing" a song is the
 * reversible `excluded` flag (honoured by every pool query and left alone
 * by `songs:sync`), never a hard delete - `rounds.song_id` /
 * `user_song_plays.song_id` cascade, so a delete would take real game
 * history with it. Metadata edits are best-effort: `songs:sync` will
 * overwrite title/artist/popularity/release_year/genre on its next pass for
 * any track still resolvable from an active seed playlist.
 */
class AdminSongController extends Controller
{
    /**
     * The only values `songs.genre` is ever set to - the SongGenre cases
     * whose cacheTag() is non-null (see App\Enums\SongGenre).
     */
    private const GENRES = ['pop', 'hip_hop', 'german_rap', 'iconic'];

    public function index(Request $request)
    {
        $search = trim((string) $request->query('search', ''));
        $genre = trim((string) $request->query('genre', ''));
        $status = (string) $request->query('status', 'all');

        $songs = Song::query()
            ->when($search !== '', function ($query) use ($search) {
                // Bindings are parameterised regardless; escaping %/_ just
                // stops a literal one acting as a wildcard.
                $like = '%'.addcslashes($search, '%_\\').'%';
                $query->where(function ($q) use ($like) {
                    $q->where('title', 'like', $like)
                        ->orWhere('artist', 'like', $like);
                });
            })
            ->when($genre !== '', fn ($query) => $genre === 'unknown'
                ? $query->whereNull('genre')
                : $query->where('genre', $genre))
            ->when($status === 'in_pool', fn ($query) => $query->where('excluded', false))
            ->when($status === 'removed', fn ($query) => $query->where('excluded', true))
            ->orderByDesc('popularity')
            ->orderBy('title')
            ->orderBy('id')
            ->paginate(50);

        return response()->json([
            'data' => collect($songs->items())->map(fn (Song $song) => $this->toAdminArray($song))->all(),
            'meta' => [
                'current_page' => $songs->currentPage(),
                'last_page' => $songs->lastPage(),
                'total' => $songs->total(),
                'pool_size' => Song::where('excluded', false)->count(),
                'removed_count' => Song::where('excluded', true)->count(),
            ],
            // Includes null (rendered as "Unknown" by the client).
            'genres' => Song::query()->select('genre')->distinct()->orderBy('genre')->pluck('genre'),
        ]);
    }

    public function show(Song $song)
    {
        return response()->json($this->toAdminArray($song));
    }

    public function update(Request $request, Song $song)
    {
        $data = $request->validate([
            'title' => ['sometimes', 'string', 'min:1', 'max:255'],
            'artist' => ['sometimes', 'string', 'min:1', 'max:255'],
            'genre' => ['sometimes', 'nullable', Rule::in(self::GENRES)],
            'popularity' => ['sometimes', 'integer', 'between:0,100'],
            'release_year' => ['sometimes', 'nullable', 'integer', 'between:1900,'.((int) date('Y') + 1)],
            'excluded' => ['sometimes', 'boolean'],
        ]);

        $song->update($data);

        return response()->json($this->toAdminArray($song->fresh()));
    }

    /**
     * Remove (or restore) many songs at once from the list view's
     * multi-select.
     */
    public function bulkExclude(Request $request)
    {
        $data = $request->validate([
            'ids' => ['required', 'array', 'min:1', 'max:500'],
            'ids.*' => ['integer', 'exists:songs,id'],
            'excluded' => ['required', 'boolean'],
        ]);

        $updated = Song::whereIn('id', $data['ids'])->update(['excluded' => $data['excluded']]);

        return response()->json(['updated' => $updated]);
    }

    /**
     * @return array<string, mixed>
     */
    private function toAdminArray(Song $song): array
    {
        return [
            'id' => $song->id,
            'provider_track_id' => $song->provider_track_id,
            'title' => $song->title,
            'artist' => $song->artist,
            'album_art_url' => $song->album_art_url,
            'genre' => $song->genre,
            'popularity' => $song->popularity,
            'release_year' => $song->release_year,
            'excluded' => (bool) $song->excluded,
            'last_used_at' => $song->last_used_at?->toIso8601String(),
            'preview_url' => $song->preview_url,
        ];
    }
}
