<?php

namespace App\Services\Music;

use RuntimeException;

/**
 * Thrown when Spotify (HTTP 429) or Apple's iTunes Search API (HTTP 403 on
 * a burst) rate-limits us. The browser-driven sync catches this, puts the
 * current track back on the queue, and pauses ~60s before resuming.
 */
class RateLimitException extends RuntimeException
{
}
