<?php

namespace App\Support;

use App\Enums\DatasetType;
use App\Models\Dataset;
use App\Models\DatasetTrack;
use App\Models\DdfQuestion;
use App\Models\DubClip;

/**
 * The one place a Workshop dataset is serialized - shared by DatasetController
 * and DubPackClipController so a nested-child mutation and a plain GET return
 * the same shape.
 */
class DatasetPresenter
{
    /** @return array<string, mixed> */
    public static function summary(Dataset $dataset): array
    {
        return [
            'id' => $dataset->id,
            'name' => $dataset->name,
            'type' => $dataset->type->value,
            'visibility' => $dataset->visibility->value,
            'review_status' => $dataset->review_status,
            'item_count' => self::itemCount($dataset),
            'updated_at' => $dataset->updated_at,
            'owner_username' => $dataset->owner?->username ?? $dataset->owner?->name,
        ];
    }

    /** @return array<string, mixed> */
    public static function detail(Dataset $dataset): array
    {
        $dataset->loadMissing('owner:id,username,name');

        $out = self::summary($dataset) + [
            'owner_id' => $dataset->owner_id,
            'language' => $dataset->language,
            'review_note' => $dataset->review_note,
        ];

        $out += match ($dataset->type) {
            DatasetType::Ddf => ['questions' => $dataset->questions()->get()->map(fn (DdfQuestion $q) => [
                'id' => $q->id,
                'text' => $q->text,
                'correct_answer' => $q->correct_answer,
                'category' => $q->category->value,
                'position' => $q->position,
            ])],
            DatasetType::Dub => ['dub_clips' => $dataset->dubClips()->withCount(['characters', 'lines'])->get()
                ->map(fn (DubClip $c) => self::dubClipRow($c))],
            default => ['tracks' => $dataset->tracks()->get()->map(fn (DatasetTrack $t) => [
                'id' => $t->id,
                'provider_track_id' => $t->provider_track_id,
                'title' => $t->title,
                'artist' => $t->artist,
                'album_art_url' => $t->album_art_url,
                'position' => $t->position,
            ])],
        };

        return $out;
    }

    private static function itemCount(Dataset $dataset): int
    {
        return match ($dataset->type) {
            DatasetType::Ddf => $dataset->questions_count ?? $dataset->questions()->count(),
            DatasetType::Dub => $dataset->dub_clips_count ?? $dataset->dubClips()->count(),
            default => $dataset->tracks_count ?? $dataset->tracks()->count(),
        };
    }

    /** @return array<string, mixed> */
    private static function dubClipRow(DubClip $clip): array
    {
        return [
            'id' => $clip->id,
            'title' => $clip->title,
            'status' => $clip->status,
            'video_url' => DubPresenter::url($clip->source_video_path),
            'source_url' => $clip->source_url,
            'processing_error' => $clip->processing_error,
            'character_count' => $clip->characters_count ?? $clip->characters()->count(),
            'line_count' => $clip->lines_count ?? $clip->lines()->count(),
            'duration_ms' => $clip->duration_ms,
            'position' => $clip->position,
        ];
    }
}
