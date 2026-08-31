<?php

namespace App\Http\Controllers\Api\Admin;

use App\Enums\CosmeticSlot;
use App\Http\Controllers\Controller;
use App\Models\Cosmetic;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

/**
 * Admin CRUD for the cosmetic library. New cosmetics carry an uploaded PNG/WebP
 * image (rendered as an <img> layer on the client) so they don't need a
 * frontend registry entry. Same auth:sanctum + admin middleware group.
 */
class AdminCosmeticController extends Controller
{
    public function index()
    {
        return response()->json([
            'cosmetics' => Cosmetic::query()->orderBy('slot')->orderBy('name')->get()
                ->map(fn (Cosmetic $c) => $this->row($c)),
            'slots' => CosmeticSlot::values(),
            'rarities' => ['common', 'rare', 'epic'],
            'sources' => ['starter', 'track', 'pass'],
        ]);
    }

    public function store(Request $request)
    {
        $data = $this->validated($request);

        $cosmetic = new Cosmetic([
            'slot' => $data['slot'],
            'key' => $this->uniqueKey($data['name']),
            'name' => $data['name'],
            'rarity' => $data['rarity'],
            'source' => $data['source'],
            'season_id' => $data['season_id'] ?? null,
        ]);

        if ($request->hasFile('image')) {
            $cosmetic->image_path = $request->file('image')->store('cosmetics', 'public');
        }

        $cosmetic->save();
        Cosmetic::forgetCatalog();

        return response()->json($this->row($cosmetic), 201);
    }

    /** POST (multipart) so a new image can ride along with the field edits. */
    public function update(Request $request, Cosmetic $cosmetic)
    {
        $data = $this->validated($request);

        $cosmetic->fill([
            'slot' => $data['slot'],
            'name' => $data['name'],
            'rarity' => $data['rarity'],
            'source' => $data['source'],
            'season_id' => $data['season_id'] ?? null,
        ]);

        if ($request->hasFile('image')) {
            if ($cosmetic->image_path) {
                Storage::disk('public')->delete($cosmetic->image_path);
            }
            $cosmetic->image_path = $request->file('image')->store('cosmetics', 'public');
        }

        $cosmetic->save();
        Cosmetic::forgetCatalog();

        return response()->json($this->row($cosmetic->fresh()));
    }

    public function destroy(Cosmetic $cosmetic)
    {
        if ($cosmetic->image_path) {
            Storage::disk('public')->delete($cosmetic->image_path);
        }

        $cosmetic->delete();
        Cosmetic::forgetCatalog();

        return response()->noContent();
    }

    /**
     * @return array<string, mixed>
     */
    private function validated(Request $request): array
    {
        return $request->validate([
            'name' => ['required', 'string', 'max:60'],
            'slot' => ['required', Rule::in(CosmeticSlot::values())],
            'rarity' => ['required', Rule::in(['common', 'rare', 'epic'])],
            'source' => ['required', Rule::in(['starter', 'track', 'pass'])],
            'season_id' => ['nullable', 'integer', 'exists:seasons,id'],
            'image' => ['nullable', 'image', 'mimes:png,webp', 'max:2048'],
        ]);
    }

    private function uniqueKey(string $name): string
    {
        $base = Str::slug($name, '_') ?: 'cosmetic';
        $key = $base;
        $n = 2;

        while (Cosmetic::where('key', $key)->exists()) {
            $key = "{$base}_{$n}";
            $n++;
        }

        return $key;
    }

    /**
     * @return array<string, mixed>
     */
    private function row(Cosmetic $c): array
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
