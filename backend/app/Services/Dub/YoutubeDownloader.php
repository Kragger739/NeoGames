<?php

namespace App\Services\Dub;

use Illuminate\Support\Facades\Process;
use RuntimeException;

/**
 * Downloads a whole video from a link with yt-dlp and probes it with
 * ffprobe. Injected into IngestYoutubeClip so tests can swap a fake that
 * writes a stub file without ever spawning a process.
 */
class YoutubeDownloader
{
    /**
     * Fetch $url into $workDir. Returns the produced .mp4 path plus metadata.
     *
     * @return array{path: string, duration_ms: int, width: int|null, height: int|null}
     *
     * @throws RuntimeException on an unreachable link, an over-long video, or a yt-dlp failure
     */
    public function download(string $url, string $workDir): array
    {
        $ytdlp = (string) config('dub.ytdlp_path');
        $maxHeight = (int) config('dub.youtube_max_height');
        $maxDuration = (int) config('dub.youtube_max_duration_seconds');
        $timeout = (int) config('dub.youtube_download_timeout');

        $probe = Process::timeout(60)->run([
            $ytdlp, '--no-warnings', '--no-playlist', '--skip-download', '--print', '%(duration)s', $url,
        ]);

        if ($probe->failed()) {
            throw new RuntimeException('Could not read that link: '.$this->tail($probe->errorOutput() ?: $probe->output()));
        }

        $duration = (int) trim($probe->output());

        if ($duration > 0 && $duration > $maxDuration) {
            throw new RuntimeException("That video is {$duration}s long; the limit is {$maxDuration}s.");
        }

        $out = rtrim($workDir, '/').'/source.%(ext)s';

        $result = Process::timeout($timeout)->run([
            $ytdlp,
            '--no-warnings', '--no-playlist', '--no-progress',
            '-f', "bv*[height<={$maxHeight}]+ba/b[height<={$maxHeight}]/b",
            '--merge-output-format', 'mp4',
            '--recode-video', 'mp4',
            '--max-filesize', '250M',
            '-o', $out,
            $url,
        ]);

        $files = glob(rtrim($workDir, '/').'/source.*') ?: [];
        $path = $files[0] ?? null;

        if ($result->failed() || $path === null || ! is_file($path)) {
            throw new RuntimeException('Download failed: '.$this->tail($result->errorOutput() ?: $result->output()));
        }

        return ['path' => $path] + $this->probe($path);
    }

    /** @return array{duration_ms: int, width: int|null, height: int|null} */
    private function probe(string $path): array
    {
        $result = Process::timeout(60)->run([
            (string) config('dub.ffprobe_path'), '-v', 'error',
            '-select_streams', 'v:0',
            '-show_entries', 'format=duration:stream=width,height',
            '-of', 'json', $path,
        ]);

        $meta = json_decode($result->output(), true) ?: [];
        $stream = $meta['streams'][0] ?? [];

        return [
            'duration_ms' => (int) round(((float) ($meta['format']['duration'] ?? 0)) * 1000),
            'width' => isset($stream['width']) ? (int) $stream['width'] : null,
            'height' => isset($stream['height']) ? (int) $stream['height'] : null,
        ];
    }

    private function tail(string $text): string
    {
        return mb_substr(trim($text), -300);
    }
}
