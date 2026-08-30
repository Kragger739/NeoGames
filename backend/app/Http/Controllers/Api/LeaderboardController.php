<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Season;
use App\Models\SeasonProgress;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;

class LeaderboardController extends Controller
{
    /**
     * Global season-XP leaderboard for the active season. Top N is cached
     * briefly; the caller's own rank is always computed live so it stays
     * accurate right after they earn XP.
     */
    public function index(Request $request)
    {
        $season = Season::current();

        if ($season === null) {
            return response()->json(['season' => null, 'entries' => [], 'me' => null]);
        }

        $entries = Cache::remember(
            "leaderboard:{$season->id}",
            now()->addSeconds((int) config('seasons.leaderboard_cache_seconds')),
            fn () => SeasonProgress::query()
                ->where('season_id', $season->id)
                ->orderByDesc('xp')
                ->orderBy('id')
                ->with('user:id,name,username,xp,avatar_path,equipped_cosmetics')
                ->limit((int) config('seasons.leaderboard_top_n'))
                ->get()
                ->values()
                ->map(fn (SeasonProgress $p, int $i) => [
                    'rank' => $i + 1,
                    'username' => $p->user->username ?? $p->user->name,
                    'season_xp' => $p->xp,
                    'avatar' => $p->user->avatarPayload(),
                ])
                ->all(),
        );

        $mine = SeasonProgress::query()
            ->where('season_id', $season->id)
            ->where('user_id', $request->user()->id)
            ->first();

        $me = $mine === null ? null : [
            'rank' => 1 + SeasonProgress::query()
                ->where('season_id', $season->id)
                ->where(fn ($q) => $q
                    ->where('xp', '>', $mine->xp)
                    ->orWhere(fn ($q) => $q->where('xp', $mine->xp)->where('id', '<', $mine->id)))
                ->count(),
            'season_xp' => $mine->xp,
        ];

        return response()->json([
            'season' => [
                'name' => $season->name,
                'slug' => $season->slug,
                'ends_at' => $season->ends_at,
            ],
            'entries' => $entries,
            'me' => $me,
        ]);
    }
}
