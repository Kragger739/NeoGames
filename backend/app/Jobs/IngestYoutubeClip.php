<?php

namespace App\Jobs;

use App\Models\DubClip;
use App\Services\Dub\YoutubeDownloader;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Http\File;
use Illuminate\Support\Facades\File as Filesystem;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Throwable;

/**
 * Downloads a link-sourced "Dub Together" clip. Modelled on
 * FetchIconicArtistCatalogue: $tries = 1, every failure is caught here and
 * written back as dub_clips.status = 'failed' + processing_error, so it
 * never lands in the generic failed_jobs table. On success the clip moves
 * to 'review' for hand-authoring in the editor.
 */
class IngestYoutubeClip implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public int $timeout = 360;

    public function __construct(public int $clipId) {}

    public function handle(YoutubeDownloader $downloader): void
    {
        $clip = DubClip::find($this->clipId);

        if (! $clip || $clip->source_url === null || $clip->status === 'ready') {
            return; // deleted, not a link clip, or already finalised
        }

        $clip->update(['status' => 'processing', 'processing_error' => null]);

        $workDir = storage_path('app/dub-tmp/'.$clip->id.'-'.Str::random(8));
        Filesystem::ensureDirectoryExists($workDir);

        try {
            $result = $downloader->download($clip->source_url, $workDir);

            $stored = Storage::disk('public')->putFileAs(
                "dub/clips/{$clip->id}",
                new File($result['path']),
                'source.mp4',
            );

            $clip->update([
                'status' => 'review',
                'source_video_path' => $stored,
                'duration_ms' => $result['duration_ms'] ?: null,
                'video_width' => $result['width'],
                'video_height' => $result['height'],
                'processing_error' => null,
                'processed_at' => now(),
            ]);
        } catch (Throwable $e) {
            $clip->update([
                'status' => 'failed',
                'processing_error' => Str::limit($e->getMessage(), 500),
            ]);
            report($e);
        } finally {
            Filesystem::deleteDirectory($workDir);
        }
    }
}
