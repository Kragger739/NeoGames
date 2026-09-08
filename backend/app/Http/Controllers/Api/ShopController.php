<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\IconicArtist;
use App\Services\NeoCoinService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * The NeoCoins shop. v1 sells permanent iconic-artist unlocks; each act is
 * either free (price 0, playable by any account) or priced (buy once, owned
 * forever via iconic_artist_user). Account-only - the route group carries
 * `verified` + `not-guest`.
 */
class ShopController extends Controller
{
    /** GET /api/shop */
    public function index(Request $request)
    {
        $user = $request->user();
        $ownedIds = $user->iconicArtists()->pluck('iconic_artists.id')->all();

        $artists = IconicArtist::query()
            ->where('enabled', true)
            ->orderBy('sort_order')
            ->orderBy('id')
            ->get()
            ->map(fn (IconicArtist $artist) => [
                'id' => $artist->id,
                'name' => $artist->name,
                'image_url' => $artist->image_url,
                'price' => (int) $artist->price,
                'owned' => $artist->price === 0 || in_array($artist->id, $ownedIds, true),
            ]);

        return response()->json([
            'neo_coins' => (int) $user->neo_coins,
            'artists' => $artists,
        ]);
    }

    /** POST /api/shop/iconic-artists/{iconicArtist}/buy */
    public function buyIconicArtist(Request $request, IconicArtist $iconicArtist, NeoCoinService $coins)
    {
        abort_unless($iconicArtist->enabled, 404);

        $user = $request->user();

        if ($iconicArtist->price <= 0) {
            throw ValidationException::withMessages([
                'shop' => ['This artist is free — no purchase needed.'],
            ]);
        }

        if ($user->iconicArtists()->whereKey($iconicArtist->id)->exists()) {
            throw ValidationException::withMessages([
                'shop' => ['You already own this artist.'],
            ]);
        }

        $paid = $coins->debit($user, (int) $iconicArtist->price, 'spend', [
            'iconic_artist_id' => $iconicArtist->id,
        ]);

        if (! $paid) {
            throw ValidationException::withMessages([
                'shop' => ['Not enough NeoCoins.'],
            ]);
        }

        DB::table('iconic_artist_user')->insertOrIgnore([
            'user_id' => $user->id,
            'iconic_artist_id' => $iconicArtist->id,
            'source' => 'shop',
            'acquired_at' => now(),
        ]);

        return response()->json([
            'neo_coins' => (int) $user->fresh()->neo_coins,
            'owned' => true,
        ]);
    }
}
