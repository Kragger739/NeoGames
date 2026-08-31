<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Models\UnlockRequirement;
use Illuminate\Http\Request;

/**
 * Admin editor for the player level required to host a game night, pick a
 * mode, or pick a genre (unlock_requirements table). Same auth:sanctum +
 * admin middleware group as the rest of the admin dashboard.
 */
class AdminUnlockController extends Controller
{
    public function index()
    {
        $levels = UnlockRequirement::map();

        $rows = collect(UnlockRequirement::labels())->map(fn (array $meta, string $key) => [
            'key' => $key,
            'label' => $meta['label'],
            'category' => $meta['category'],
            'required_level' => $levels[$key] ?? 1,
        ])->values();

        return response()->json(['requirements' => $rows]);
    }

    public function update(Request $request, string $key)
    {
        abort_unless(array_key_exists($key, UnlockRequirement::labels()), 404);

        $data = $request->validate([
            'required_level' => ['required', 'integer', 'min:1', 'max:999'],
        ]);

        $row = UnlockRequirement::updateOrCreate(
            ['key' => $key],
            ['required_level' => $data['required_level']],
        );

        return response()->json([
            'key' => $row->key,
            'required_level' => $row->required_level,
        ]);
    }
}
