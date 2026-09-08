<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Models\IconicArtist;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;

/**
 * Admin CRUD for the Iconic Artist series carousel. Mirrors
 * AdminCosmeticController: multipart, POST for update so a new photo can
 * ride along with the field edits, old file cleaned up on the `public` disk.
 */
class AdminIconicArtistController extends Controller
{
    public function index()
    {
        return response()->json([
            'artists' => IconicArtist::query()
                ->orderBy('sort_order')
                ->orderBy('id')
                ->get()
                ->map(fn (IconicArtist $artist) => $this->row($artist)),
        ]);
    }

    public function store(Request $request)
    {
        $data = $this->validated($request);

        $artist = new IconicArtist([
            'name' => $data['name'],
            // Read via the typed helpers - multipart sends these as strings
            // ("0"/"false"), which the model's boolean cast would misread.
            'enabled' => $request->boolean('enabled', true),
            'sort_order' => $request->integer('sort_order', 0),
            'price' => $request->integer('price', 0),
        ]);

        if ($request->hasFile('image')) {
            $artist->image_path = $request->file('image')->store('iconic-artists', 'public');
        }

        $artist->save();
        $artist->startCatalogueFetch();

        return response()->json($this->row($artist), 201);
    }

    /** POST (multipart) so a new photo can ride along with the field edits. */
    public function update(Request $request, IconicArtist $iconicArtist)
    {
        $data = $this->validated($request);

        $iconicArtist->fill([
            'name' => $data['name'],
            'enabled' => $request->boolean('enabled', $iconicArtist->enabled),
            'sort_order' => $request->integer('sort_order', $iconicArtist->sort_order),
            'price' => $request->integer('price', $iconicArtist->price),
        ]);

        if ($request->hasFile('image')) {
            if ($iconicArtist->image_path) {
                Storage::disk('public')->delete($iconicArtist->image_path);
            }
            $iconicArtist->image_path = $request->file('image')->store('iconic-artists', 'public');
        }

        $nameChanged = $iconicArtist->isDirty('name');
        $iconicArtist->save();

        // A new identity means a new catalogue - the old rows get demoted
        // (rank nulled) by the fetch job's upsert sweep.
        if ($nameChanged) {
            $iconicArtist->startCatalogueFetch();
        }

        return response()->json($this->row($iconicArtist->fresh()));
    }

    public function refetch(IconicArtist $iconicArtist)
    {
        $iconicArtist->startCatalogueFetch();

        return response()->json($this->row($iconicArtist->fresh()));
    }

    public function destroy(IconicArtist $iconicArtist)
    {
        if ($iconicArtist->image_path) {
            Storage::disk('public')->delete($iconicArtist->image_path);
        }

        // iconic_artist_songs rows cascade. Linked `songs` rows are left
        // alone - they're the shared, songs:sync-regenerable pool and may
        // back other rooms' rounds.
        $iconicArtist->delete();

        return response()->noContent();
    }

    /**
     * @return array<string, mixed>
     */
    private function validated(Request $request): array
    {
        return $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'enabled' => ['sometimes', 'boolean'],
            'sort_order' => ['sometimes', 'integer', 'min:0', 'max:9999'],
            'price' => ['sometimes', 'integer', 'min:0', 'max:1000000'],
            'image' => ['nullable', 'image', 'mimes:png,webp,jpg,jpeg', 'max:4096'],
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function row(IconicArtist $artist): array
    {
        $resolved = $artist->songs()
            ->where(fn ($q) => $q->whereNotNull('song_id')->orWhere('unplayable', true))
            ->count();

        return [
            'id' => $artist->id,
            'name' => $artist->name,
            'image_url' => $artist->image_url,
            'enabled' => (bool) $artist->enabled,
            'sort_order' => $artist->sort_order,
            'price' => (int) $artist->price,
            'free_this_week' => $artist->id === IconicArtist::freeThisWeek()?->id,
            'pool_size' => $artist->poolCount(),
            'fetch_status' => $artist->fetch_status,
            'fetch_total' => $artist->fetched_total,
            'fetch_resolved' => $resolved,
            'fetched_playable' => $artist->fetched_playable,
            'top20_count' => $artist->playableTopCount(20),
            'fetch_error' => $artist->fetch_error,
            'fetched_at' => $artist->fetched_at?->toIso8601String(),
        ];
    }
}
