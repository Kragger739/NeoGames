<?php

return [

    /*
    |--------------------------------------------------------------------------
    | "Dub Together" YouTube ingestion
    |--------------------------------------------------------------------------
    |
    | yt-dlp + ffprobe are installed in the backend image (see Dockerfile).
    | A pasted link is downloaded whole; the person trims it to a clip in
    | the review-and-fix editor afterwards.
    |
    */

    'ytdlp_path' => env('DUB_YTDLP_PATH', 'yt-dlp'),
    'ffprobe_path' => env('DUB_FFPROBE_PATH', 'ffprobe'),

    // Refuse a video longer than this (seconds) so downloads stay sane.
    'youtube_max_duration_seconds' => (int) env('DUB_YOUTUBE_MAX_DURATION', 900),

    // Hard timeout for the whole yt-dlp download (seconds).
    'youtube_download_timeout' => (int) env('DUB_YOUTUBE_DOWNLOAD_TIMEOUT', 300),

    // Cap the downloaded height so files stay small.
    'youtube_max_height' => (int) env('DUB_YOUTUBE_MAX_HEIGHT', 720),
];
