<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\UpdateAvatarRequest;
use App\Http\Requests\UpdateCosmeticsRequest;
use App\Http\Requests\UpdateProfileRequest;
use App\Models\Cosmetic;
use App\Models\Season;
use App\Models\SeasonProgress;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Session;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;

class ProfileController extends Controller
{
    public function update(UpdateProfileRequest $request)
    {
        $user = $request->user();
        $user->update(['username' => $request->validated('username')]);

        return response()->json($user);
    }

    /**
     * Replaces the user's profile picture - the old file (if any) is
     * removed first so a string of re-uploads doesn't leak orphaned files
     * on the 'public' disk forever.
     */
    public function updateAvatar(UpdateAvatarRequest $request)
    {
        $user = $request->user();

        if ($user->avatar_path) {
            Storage::disk('public')->delete($user->avatar_path);
        }

        $path = $request->file('avatar')->store('avatars', 'public');

        $user->update(['avatar_path' => $path]);

        return response()->json($user);
    }

    /**
     * Permanently delete the authenticated user's account.
     *
     * Re-auth is required: a password account confirms with its password;
     * an OAuth-only account (random password it never knows) confirms by
     * typing its own username. FK cascades then remove the user's datasets,
     * hosted rooms (and their room_players), friendships, season progress,
     * cosmetics and email-verification codes; room_players.user_id is
     * nullOnDelete so other players' game history stays intact.
     */
    public function destroy(Request $request)
    {
        $user = $request->user();

        if ($user->provider) {
            $request->validate(['confirmation' => ['required', 'string']]);

            if (! hash_equals((string) $user->username, (string) $request->input('confirmation'))) {
                throw ValidationException::withMessages([
                    'confirmation' => ['Please type your username exactly to confirm.'],
                ]);
            }
        } else {
            $request->validate(['password' => ['required', 'string', 'current_password']]);
        }

        $user->purgeArtifacts();

        Auth::guard('web')->logout();
        Session::invalidate();
        Session::regenerateToken();

        $user->delete();

        return response()->noContent();
    }

    public function destroyAvatar(Request $request)
    {
        $user = $request->user();

        if ($user->avatar_path) {
            Storage::disk('public')->delete($user->avatar_path);
            $user->update(['avatar_path' => null]);
        }

        return response()->json($user);
    }

    /**
     * Everything the /profile/cosmetics screen needs in one call: the active
     * season, this user's season progress, their equipped map, the full
     * wardrobe (starters + this season's track, each flagged owned), and the
     * tier ladder for the season-pass progress bar.
     */
    public function cosmetics(Request $request)
    {
        $user = $request->user();
        $season = Season::current();

        $ownedIds = $user->cosmetics()->pluck('cosmetics.id')
            ->merge(Cosmetic::where('source', 'starter')->pluck('id'))
            ->unique()
            ->values();

        $catalog = Cosmetic::query()
            ->where(fn ($q) => $q
                ->where('source', 'starter')
                ->when($season, fn ($q) => $q->orWhere('season_id', $season->id)))
            ->orderBy('slot')
            ->orderBy('tier')
            ->get();

        $progress = $season
            ? SeasonProgress::firstOrNew(['season_id' => $season->id, 'user_id' => $user->id])
            : null;

        return response()->json([
            'season' => $season ? [
                'name' => $season->name,
                'slug' => $season->slug,
                'starts_at' => $season->starts_at,
                'ends_at' => $season->ends_at,
            ] : null,
            'progress' => [
                'xp' => (int) ($progress->xp ?? 0),
                'current_tier' => (int) ($progress->current_tier ?? 0),
            ],
            'equipped' => (object) ($user->equipped_cosmetics ?? []),
            'catalog' => $catalog->map(fn (Cosmetic $c) => [
                'id' => $c->id,
                'slot' => $c->slot->value,
                'key' => $c->key,
                'name' => $c->name,
                'rarity' => $c->rarity,
                'source' => $c->source,
                'tier' => $c->tier,
                'owned' => $ownedIds->contains($c->id),
            ]),
            'tiers' => $season ? $this->tierLadder($season, $ownedIds->all()) : [],
        ]);
    }

    /**
     * Persists the equipped map. UpdateCosmeticsRequest has already checked
     * every id is owned and slot-matched; nulls just clear a slot.
     */
    public function updateCosmetics(UpdateCosmeticsRequest $request)
    {
        $user = $request->user();

        $equipped = collect($request->validated('equipped'))
            ->reject(fn ($id) => $id === null)
            ->map(fn ($id) => (int) $id)
            ->all();

        $user->update(['equipped_cosmetics' => $equipped === [] ? null : $equipped]);

        return response()->json($user);
    }

    /**
     * @param  list<int>  $ownedIds
     * @return list<array{tier:int, threshold:int, cosmetic:array<string,mixed>|null, owned:bool}>
     */
    private function tierLadder(Season $season, array $ownedIds): array
    {
        $thresholds = config('seasons.tier_thresholds');

        $byTier = Cosmetic::query()
            ->where('season_id', $season->id)
            ->where('source', 'track')
            ->get()
            ->keyBy('tier');

        $ladder = [];

        foreach ($thresholds as $index => $threshold) {
            $tier = $index + 1;
            $cosmetic = $byTier->get($tier);

            $ladder[] = [
                'tier' => $tier,
                'threshold' => (int) $threshold,
                'cosmetic' => $cosmetic ? [
                    'id' => $cosmetic->id,
                    'slot' => $cosmetic->slot->value,
                    'key' => $cosmetic->key,
                    'name' => $cosmetic->name,
                    'rarity' => $cosmetic->rarity,
                ] : null,
                'owned' => $cosmetic ? in_array($cosmetic->id, $ownedIds, true) : false,
            ];
        }

        return $ladder;
    }
}
