<?php

namespace App\Services\Dub;

use App\Jobs\IngestYoutubeClip;
use App\Models\DubClip;
use App\Support\DubPresenter;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;

/**
 * All the "author one Dub Together clip" logic in one place, shared by the
 * admin library (AdminDubClipController) and the Workshop pack routes
 * (Phase 9). Callers pass already-shape-validated arrays; this class owns
 * the semantic checks, persistence, and serialization.
 */
class DubClipManager
{
    /** @param  array<string, mixed>  $attrs  source / created_by_user_id / dataset_id / game_room_id, plus title */
    public function createFromUpload(array $attrs, UploadedFile $video, ?int $durationMs = null): DubClip
    {
        $clip = DubClip::create([
            'status' => 'review',
            'is_public' => false,
            'duration_ms' => $durationMs ?: null,
            ...$attrs,
        ]);

        $clip->update([
            'source_video_path' => $video->store("dub/clips/{$clip->id}", 'public'),
        ]);

        return $clip->fresh();
    }

    /** @param  array<string, mixed>  $attrs */
    public function createFromLink(array $attrs, string $url): DubClip
    {
        $clip = DubClip::create([
            'status' => 'processing',
            'is_public' => false,
            'source_url' => $url,
            'processing_error' => null,
            ...$attrs,
        ]);

        IngestYoutubeClip::dispatch($clip->id);

        return $clip->fresh();
    }

    public function reingest(DubClip $clip): void
    {
        if ($clip->source_url === null) {
            throw ValidationException::withMessages(['clip' => ['This clip was not imported from a link.']]);
        }

        $clip->update(['status' => 'processing', 'processing_error' => null]);

        IngestYoutubeClip::dispatch($clip->id);
    }

    /**
     * @param  array<string, mixed>  $data  title? / is_public?
     */
    public function updateMeta(DubClip $clip, array $data, ?UploadedFile $video): DubClip
    {
        if (array_key_exists('title', $data)) {
            $clip->title = $data['title'];
        }

        if (array_key_exists('is_public', $data)) {
            $clip->is_public = (bool) $data['is_public'];
        }

        if ($video) {
            if ($clip->source_video_path) {
                Storage::disk('public')->delete($clip->source_video_path);
            }
            $clip->source_video_path = $video->store("dub/clips/{$clip->id}", 'public');
            $clip->duration_ms = null;
            $clip->video_width = null;
            $clip->video_height = null;
            $clip->video_fps = null;
        }

        $clip->save();

        return $clip->fresh()->loadCount(['characters', 'lines']);
    }

    /**
     * Full replace of the clip's characters + lines. `ref` links a line to a
     * character (existing rows pass their real id, new rows a "tmp-N" key).
     * Blocked once the clip is published.
     *
     * @param  array<int, array{ref: string, display_name: string, color: ?string}>  $characters
     * @param  array<int, array{character_ref: string, start_ms: int, end_ms: int, text: ?string}>  $lines
     */
    public function replaceScript(DubClip $clip, array $characters, array $lines, ?int $clipStartMs, ?int $clipEndMs): DubClip
    {
        if ($clip->status === 'ready') {
            throw ValidationException::withMessages(['clip' => ['Unpublish the clip before editing it.']]);
        }

        $refs = array_column($characters, 'ref');

        if (count($refs) !== count(array_unique($refs))) {
            throw ValidationException::withMessages(['characters' => ['Character refs must be unique.']]);
        }

        foreach ($lines as $i => $line) {
            if (! in_array($line['character_ref'], $refs, true)) {
                throw ValidationException::withMessages(["lines.{$i}.character_ref" => ['Unknown character.']]);
            }
            if ($line['end_ms'] <= $line['start_ms']) {
                throw ValidationException::withMessages(["lines.{$i}.end_ms" => ['A line must end after it starts.']]);
            }
        }

        if ($clipStartMs !== null && $clipEndMs !== null && $clipEndMs <= $clipStartMs) {
            throw ValidationException::withMessages(['clip_end_ms' => ['The trim must end after it starts.']]);
        }

        DB::transaction(function () use ($clip, $characters, $lines, $clipStartMs, $clipEndMs) {
            $clip->lines()->delete();
            $clip->characters()->delete();

            $refToId = [];
            foreach (array_values($characters) as $i => $character) {
                $row = $clip->characters()->create([
                    'key' => $character['ref'],
                    'display_name' => $character['display_name'],
                    'color' => $character['color'] ?? DubClip::HUES[$i % count(DubClip::HUES)],
                    'position' => $i,
                ]);
                $refToId[$character['ref']] = $row->id;
            }

            foreach (array_values($lines) as $i => $line) {
                $clip->lines()->create([
                    'dub_clip_character_id' => $refToId[$line['character_ref']],
                    'position' => $i,
                    'start_ms' => $line['start_ms'],
                    'end_ms' => $line['end_ms'],
                    'text' => $line['text'] ?? null,
                ]);
            }

            $attrs = ['clip_start_ms' => $clipStartMs, 'clip_end_ms' => $clipEndMs];
            if ($clip->duration_ms === null && $lines !== []) {
                $attrs['duration_ms'] = max(array_column($lines, 'end_ms'));
            }
            $clip->update($attrs);
        });

        return $clip->fresh();
    }

    public function publish(DubClip $clip): DubClip
    {
        $characters = $clip->characters()->get();
        $lines = $clip->lines()->get();
        $characterIds = $characters->pluck('id')->all();

        $errors = [];

        if (! $clip->source_video_path) {
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

        $clip->update([
            'status' => 'ready',
            'duration_ms' => $clip->duration_ms ?? (int) $lines->max('end_ms'),
        ]);

        return $clip->fresh()->loadCount(['characters', 'lines']);
    }

    public function unpublish(DubClip $clip): DubClip
    {
        $clip->update(['status' => 'review']);

        return $clip->fresh()->loadCount(['characters', 'lines']);
    }

    public function delete(DubClip $clip): void
    {
        Storage::disk('public')->deleteDirectory("dub/clips/{$clip->id}");
        $clip->delete(); // characters + lines cascade
    }

    /** @return array<string, mixed> */
    public function row(DubClip $clip): array
    {
        return [
            'id' => $clip->id,
            'title' => $clip->title,
            'status' => $clip->status,
            'source' => $clip->source,
            'is_public' => (bool) $clip->is_public,
            'video_url' => DubPresenter::url($clip->source_video_path),
            'source_url' => $clip->source_url,
            'processing_error' => $clip->processing_error,
            'duration_ms' => $clip->duration_ms,
            'character_count' => $clip->characters_count ?? $clip->characters()->count(),
            'line_count' => $clip->lines_count ?? $clip->lines()->count(),
            'position' => $clip->position,
            'created_at' => $clip->created_at?->toIso8601String(),
        ];
    }

    /** @return array<string, mixed> */
    public function detail(DubClip $clip): array
    {
        return [
            ...DubPresenter::clip($clip),
            'is_public' => (bool) $clip->is_public,
            'source' => $clip->source,
            'source_url' => $clip->source_url,
            'clip_start_ms' => $clip->clip_start_ms,
            'clip_end_ms' => $clip->clip_end_ms,
            'processing_error' => $clip->processing_error,
            'created_at' => $clip->created_at?->toIso8601String(),
        ];
    }
}
