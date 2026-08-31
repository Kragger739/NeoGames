<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Models\DailyChallenge;
use App\Models\Song;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Validation\ValidationException;

/**
 * Admin override for a day's Daily challenge songs. A day left untouched is
 * generated deterministically from its date (see DailyChallenge::forDate);
 * saving here pins an explicit five and marks the day `curated`.
 */
class AdminDailyController extends Controller
{
    /** GET /api/admin/daily-songs/search?q= - song lookup for the picker. */
    public function songSearch(Request $request)
    {
        $request->validate(['q' => ['required', 'string', 'min:2', 'max:100']]);

        $like = '%'.addcslashes(trim((string) $request->query('q')), '%_\\').'%';

        return response()->json([
            'results' => Song::query()
                ->where('excluded', false)
                ->where(fn ($q) => $q->where('title', 'like', $like)->orWhere('artist', 'like', $like))
                ->orderByDesc('popularity')
                ->limit(10)
                ->get(['id', 'title', 'artist', 'album_art_url']),
        ]);
    }

    public function show(?string $date = null)
    {
        $challenge = DailyChallenge::forDate($this->parseDate($date));

        return response()->json($this->present($challenge));
    }

    public function update(Request $request, string $date)
    {
        $day = $this->parseDate($date);

        if ($day->toDateString() < now()->toDateString()) {
            throw ValidationException::withMessages(['date' => ['That day is already in the past.']]);
        }

        $data = $request->validate([
            'song_ids' => ['required', 'array', 'size:'.DailyChallenge::SONG_COUNT],
            'song_ids.*' => ['integer', 'distinct', 'exists:songs,id'],
        ]);

        $challenge = DailyChallenge::forDate($day);
        $challenge->update(['song_ids' => array_map('intval', $data['song_ids']), 'curated' => true]);

        return response()->json($this->present($challenge->fresh()));
    }

    private function parseDate(?string $date): Carbon
    {
        if ($date === null || $date === '') {
            return now();
        }

        try {
            return Carbon::createFromFormat('Y-m-d', $date)->startOfDay();
        } catch (\Throwable) {
            throw ValidationException::withMessages(['date' => ['Use a YYYY-MM-DD date.']]);
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function present(DailyChallenge $challenge): array
    {
        return [
            'date' => $challenge->date->toDateString(),
            'curated' => $challenge->curated,
            'has_attempts' => $challenge->attempts()->exists(),
            'songs' => $challenge->songs()->map(fn (Song $song) => [
                'id' => $song->id,
                'title' => $song->title,
                'artist' => $song->artist,
                'album_art_url' => $song->album_art_url,
            ]),
        ];
    }
}
