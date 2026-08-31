<?php

namespace App\Http\Controllers\Api\Admin;

use App\Enums\CosmeticSlot;
use App\Http\Controllers\Controller;
use App\Models\Cosmetic;
use App\Models\Season;
use App\Models\SeasonTier;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Admin CRUD for seasons and their battlepass ladder. Same auth:sanctum +
 * admin middleware group as the rest of the admin dashboard.
 */
class AdminSeasonController extends Controller
{
    public function index()
    {
        $current = Season::current();

        $seasons = Season::query()->orderByDesc('starts_at')->get()->map(fn (Season $s) => [
            'id' => $s->id,
            'name' => $s->name,
            'slug' => $s->slug,
            'starts_at' => $s->starts_at?->toIso8601String(),
            'ends_at' => $s->ends_at?->toIso8601String(),
            'is_current' => $current?->id === $s->id,
            'tier_count' => $s->tiers()->count(),
            'player_count' => $s->progress()->count(),
            'tiers' => $s->tiers()->get()->map(fn (SeasonTier $t) => [
                'tier' => $t->tier,
                'xp_threshold' => $t->xp_threshold,
                'free_cosmetic_id' => $t->free_cosmetic_id,
                'premium_cosmetic_id' => $t->premium_cosmetic_id,
            ]),
        ]);

        return response()->json([
            'seasons' => $seasons,
            'cosmetics' => Cosmetic::query()->orderBy('slot')->orderBy('name')->get()
                ->map(fn (Cosmetic $c) => $this->cosmeticRow($c)),
            'slots' => CosmeticSlot::values(),
        ]);
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:60'],
            'starts_at' => ['nullable', 'date'],
            'length_days' => ['nullable', 'integer', 'min:1', 'max:365'],
            'ends_at' => ['nullable', 'date'],
            'clone_from' => ['nullable', 'integer', 'exists:seasons,id'],
        ]);

        $startsAt = isset($data['starts_at']) ? Carbon::parse($data['starts_at']) : now();

        $endsAt = isset($data['ends_at'])
            ? Carbon::parse($data['ends_at'])
            : $startsAt->copy()->addDays($data['length_days'] ?? (int) config('seasons.season_length_days'));

        if ($endsAt->lte($startsAt)) {
            throw ValidationException::withMessages(['ends_at' => ['The season must end after it starts.']]);
        }

        $season = Season::create([
            'name' => $data['name'],
            'slug' => $this->uniqueSlug($data['name']),
            'starts_at' => $startsAt,
            'ends_at' => $endsAt,
        ]);

        if (! empty($data['clone_from'])) {
            $source = Season::with('tiers')->find($data['clone_from']);

            foreach ($source->tiers as $t) {
                $season->tiers()->create([
                    'tier' => $t->tier,
                    'xp_threshold' => $t->xp_threshold,
                    'free_cosmetic_id' => $t->free_cosmetic_id,
                    'premium_cosmetic_id' => $t->premium_cosmetic_id,
                ]);
            }
        }

        return response()->json($this->seasonRow($season->fresh()), 201);
    }

    public function update(Request $request, Season $season)
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:60'],
            'starts_at' => ['required', 'date'],
            'ends_at' => ['required', 'date', 'after:starts_at'],
        ]);

        $season->update([
            'name' => $data['name'],
            'starts_at' => Carbon::parse($data['starts_at']),
            'ends_at' => Carbon::parse($data['ends_at']),
        ]);

        return response()->json($this->seasonRow($season->fresh()));
    }

    public function destroy(Season $season)
    {
        if (Season::count() <= 1) {
            throw ValidationException::withMessages(['season' => ['You cannot delete the only season.']]);
        }

        // Cascades season_progress + season_tiers; cosmetics.season_id nulls.
        $season->delete();

        return response()->noContent();
    }

    /** PUT /seasons/{season}/tiers - replace the whole ladder. */
    public function syncTiers(Request $request, Season $season)
    {
        $data = $request->validate([
            'tiers' => ['present', 'array'],
            'tiers.*.xp_threshold' => ['required', 'integer', 'min:1', 'max:1000000'],
            'tiers.*.free_cosmetic_id' => ['nullable', 'integer', 'exists:cosmetics,id'],
            'tiers.*.premium_cosmetic_id' => ['nullable', 'integer', 'exists:cosmetics,id'],
        ]);

        $rows = $data['tiers'];
        $last = 0;

        foreach ($rows as $i => $row) {
            if ($row['xp_threshold'] <= $last) {
                throw ValidationException::withMessages([
                    "tiers.{$i}.xp_threshold" => ['Each tier must need more XP than the one before it.'],
                ]);
            }
            $last = $row['xp_threshold'];
        }

        $season->tiers()->delete();

        foreach (array_values($rows) as $i => $row) {
            $season->tiers()->create([
                'tier' => $i + 1,
                'xp_threshold' => $row['xp_threshold'],
                'free_cosmetic_id' => $row['free_cosmetic_id'] ?? null,
                'premium_cosmetic_id' => $row['premium_cosmetic_id'] ?? null,
            ]);
        }

        return response()->json($this->seasonRow($season->fresh()));
    }

    private function uniqueSlug(string $name): string
    {
        $base = Str::slug($name) ?: 'season';
        $slug = $base;
        $n = 2;

        while (Season::where('slug', $slug)->exists()) {
            $slug = "{$base}-{$n}";
            $n++;
        }

        return $slug;
    }

    /**
     * @return array<string, mixed>
     */
    private function seasonRow(Season $season): array
    {
        $current = Season::current();

        return [
            'id' => $season->id,
            'name' => $season->name,
            'slug' => $season->slug,
            'starts_at' => $season->starts_at?->toIso8601String(),
            'ends_at' => $season->ends_at?->toIso8601String(),
            'is_current' => $current?->id === $season->id,
            'tier_count' => $season->tiers()->count(),
            'player_count' => $season->progress()->count(),
            'tiers' => $season->tiers()->get()->map(fn (SeasonTier $t) => [
                'tier' => $t->tier,
                'xp_threshold' => $t->xp_threshold,
                'free_cosmetic_id' => $t->free_cosmetic_id,
                'premium_cosmetic_id' => $t->premium_cosmetic_id,
            ]),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function cosmeticRow(Cosmetic $c): array
    {
        return [
            'id' => $c->id,
            'slot' => $c->slot->value,
            'key' => $c->key,
            'name' => $c->name,
            'rarity' => $c->rarity,
            'source' => $c->source,
            'season_id' => $c->season_id,
            'image_url' => $c->image_url,
            'has_registry_svg' => $c->image_path === null,
        ];
    }
}
