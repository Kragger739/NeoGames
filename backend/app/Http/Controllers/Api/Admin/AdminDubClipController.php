<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Models\DubClip;
use App\Support\DubPresenter;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * Admin CRUD for the "Dub Together" clip library. Mirrors
 * AdminIconicArtistController: multipart, POST for update so a replacement
 * video can ride along, old file cleaned up on the `public` disk. Phase 2
 * has no ML sidecar - an admin uploads a video and hand-authors the
 * characters + timed lines, then publishes (status review -> ready), which
 * is what DubClipController@index surfaces in the lobby picker.
 */
class AdminDubClipController extends Controller
{
    public function index()
    {
        return response()->json([
            'clips' => DubClip::query()
                ->withCount(['characters', 'lines'])
                ->orderByDesc('id')
                ->get()
                ->map(fn (DubClip $clip) => $this->row($clip)),
        ]);
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            'title' => ['required', 'string', 'max:120'],
            'video' => ['required', 'file', 'mimetypes:video/mp4,video/webm,video/quicktime', 'max:40960'],
            'duration_ms' => ['nullable', 'integer', 'min:0'],
        ]);

        $clip = DubClip::create([
            'source' => 'library',
            'created_by_user_id' => $request->user()->id,
            'title' => $data['title'],
            'status' => 'review',
            'is_public' => false,
            'duration_ms' => $request->integer('duration_ms') ?: null,
        ]);

        $clip->update([
            'source_video_path' => $request->file('video')->store("dub/clips/{$clip->id}", 'public'),
        ]);

        return response()->json($this->row($clip->fresh()), 201);
    }

    public function show(DubClip $dubClip)
    {
        return response()->json($this->detail($dubClip));
    }

    /** POST (multipart) so a replacement video can ride along with the field edits. */
    public function update(Request $request, DubClip $dubClip)
    {
        $request->validate([
            'title' => ['sometimes', 'string', 'max:120'],
            'is_public' => ['sometimes', 'boolean'],
            'video' => ['sometimes', 'file', 'mimetypes:video/mp4,video/webm,video/quicktime', 'max:40960'],
        ]);

        if ($request->has('title')) {
            $dubClip->title = $request->string('title');
        }

        if ($request->has('is_public')) {
            $dubClip->is_public = $request->boolean('is_public');
        }

        if ($request->hasFile('video')) {
            if ($dubClip->source_video_path) {
                Storage::disk('public')->delete($dubClip->source_video_path);
            }
            $dubClip->source_video_path = $request->file('video')->store("dub/clips/{$dubClip->id}", 'public');
            $dubClip->duration_ms = null;
            $dubClip->video_width = null;
            $dubClip->video_height = null;
            $dubClip->video_fps = null;
        }

        $dubClip->save();

        return response()->json($this->row($dubClip->fresh()->loadCount(['characters', 'lines'])));
    }

    /**
     * Full replace of the clip's characters + lines. Client sends the whole
     * script; `ref` links a line to a character (existing rows pass their
     * real id as `ref`, new rows a "tmp-N" key). Blocked once the clip is
     * published - unpublish to edit.
     */
    public function putScript(Request $request, DubClip $dubClip)
    {
        if ($dubClip->status === 'ready') {
            throw ValidationException::withMessages(['clip' => ['Unpublish the clip before editing it.']]);
        }

        $data = $request->validate([
            'characters' => ['present', 'array'],
            'characters.*.ref' => ['required', 'string', 'max:40'],
            'characters.*.display_name' => ['required', 'string', 'max:80'],
            'characters.*.color' => ['nullable', Rule::in(DubClip::HUES)],
            'lines' => ['present', 'array'],
            'lines.*.character_ref' => ['required', 'string', 'max:40'],
            'lines.*.start_ms' => ['required', 'integer', 'min:0'],
            'lines.*.end_ms' => ['required', 'integer', 'min:1'],
            'lines.*.text' => ['nullable', 'string', 'max:500'],
        ]);

        $refs = array_column($data['characters'], 'ref');

        if (count($refs) !== count(array_unique($refs))) {
            throw ValidationException::withMessages(['characters' => ['Character refs must be unique.']]);
        }

        foreach ($data['lines'] as $i => $line) {
            if (! in_array($line['character_ref'], $refs, true)) {
                throw ValidationException::withMessages(["lines.{$i}.character_ref" => ['Unknown character.']]);
            }
            if ($line['end_ms'] <= $line['start_ms']) {
                throw ValidationException::withMessages(["lines.{$i}.end_ms" => ['A line must end after it starts.']]);
            }
        }

        DB::transaction(function () use ($dubClip, $data) {
            $dubClip->lines()->delete();
            $dubClip->characters()->delete();

            $refToId = [];
            foreach (array_values($data['characters']) as $i => $character) {
                $row = $dubClip->characters()->create([
                    'key' => $character['ref'],
                    'display_name' => $character['display_name'],
                    'color' => $character['color'] ?? DubClip::HUES[$i % count(DubClip::HUES)],
                    'position' => $i,
                ]);
                $refToId[$character['ref']] = $row->id;
            }

            foreach (array_values($data['lines']) as $i => $line) {
                $dubClip->lines()->create([
                    'dub_clip_character_id' => $refToId[$line['character_ref']],
                    'position' => $i,
                    'start_ms' => $line['start_ms'],
                    'end_ms' => $line['end_ms'],
                    'text' => $line['text'] ?? null,
                ]);
            }

            if ($dubClip->duration_ms === null && $data['lines'] !== []) {
                $dubClip->update(['duration_ms' => max(array_column($data['lines'], 'end_ms'))]);
            }
        });

        return response()->json($this->detail($dubClip->fresh()));
    }

    public function publish(DubClip $dubClip)
    {
        $characters = $dubClip->characters()->get();
        $lines = $dubClip->lines()->get();
        $characterIds = $characters->pluck('id')->all();

        $errors = [];

        if (! $dubClip->source_video_path) {
            $errors[] = 'The clip needs a video.';
        }
        if ($characters->isEmpty()) {
            $errors[] = 'Add at least one character.';
        }
        if ($lines->isEmpty()) {
            $errors[] = 'Add at least one line.';
        }
        foreach ($lines as $line) {
            if (! in_array($line->dub_clip_character_id, $characterIds, true)) {
                $errors[] = 'Every line needs a character in this clip.';
            }
            if ($line->end_ms <= $line->start_ms) {
                $errors[] = 'Every line must end after it starts.';
            }
        }

        if ($errors !== []) {
            throw ValidationException::withMessages(['clip' => array_values(array_unique($errors))]);
        }

        $dubClip->update([
            'status' => 'ready',
            'duration_ms' => $dubClip->duration_ms ?? (int) $lines->max('end_ms'),
        ]);

        return response()->json($this->row($dubClip->fresh()->loadCount(['characters', 'lines'])));
    }

    public function unpublish(DubClip $dubClip)
    {
        $dubClip->update(['status' => 'review']);

        return response()->json($this->row($dubClip->fresh()->loadCount(['characters', 'lines'])));
    }

    public function destroy(DubClip $dubClip)
    {
        Storage::disk('public')->deleteDirectory("dub/clips/{$dubClip->id}");
        $dubClip->delete(); // characters + lines cascade

        return response()->noContent();
    }

    /** @return array<string, mixed> */
    private function row(DubClip $clip): array
    {
        return [
            'id' => $clip->id,
            'title' => $clip->title,
            'status' => $clip->status,
            'source' => $clip->source,
            'is_public' => (bool) $clip->is_public,
            'video_url' => DubPresenter::url($clip->source_video_path),
            'duration_ms' => $clip->duration_ms,
            'character_count' => $clip->characters_count ?? $clip->characters()->count(),
            'line_count' => $clip->lines_count ?? $clip->lines()->count(),
            'created_at' => $clip->created_at?->toIso8601String(),
        ];
    }

    /** @return array<string, mixed> */
    private function detail(DubClip $clip): array
    {
        return [
            ...DubPresenter::clip($clip),
            'is_public' => (bool) $clip->is_public,
            'source' => $clip->source,
            'created_at' => $clip->created_at?->toIso8601String(),
        ];
    }
}
