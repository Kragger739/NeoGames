<?php

namespace Database\Seeders;

use App\Models\Cosmetic;
use App\Models\Season;
use Illuminate\Database\Seeder;

/**
 * Season 1 plus its Phase 1 cosmetic catalogue: 3 starter items everyone owns
 * implicitly, and 10 free "track" items unlocked by playing (one per tier).
 * Every `key` maps to a hand-authored SVG in
 * frontend/src/lib/cosmetics/registry.tsx.
 */
class SeasonSeeder extends Seeder
{
    public function run(): void
    {
        $season = Season::query()->firstOrCreate(
            ['slug' => 'season-1'],
            [
                'name' => 'Season 1',
                'starts_at' => now(),
                'ends_at' => now()->addDays((int) config('seasons.season_length_days')),
            ],
        );

        $starters = [
            ['slot' => 'frame', 'key' => 'frame_soft', 'name' => 'Soft Ring', 'rarity' => 'common'],
            ['slot' => 'badge', 'key' => 'badge_dot', 'name' => 'Little Dot', 'rarity' => 'common'],
            ['slot' => 'background', 'key' => 'bg_wash', 'name' => 'Cream Wash', 'rarity' => 'common'],
        ];

        foreach ($starters as $row) {
            Cosmetic::query()->firstOrCreate(
                ['key' => $row['key']],
                [...$row, 'source' => 'starter', 'season_id' => null, 'tier' => null],
            );
        }

        $track = [
            1 => ['slot' => 'frame', 'key' => 'frame_dashed', 'name' => 'Dashed Frame', 'rarity' => 'common'],
            2 => ['slot' => 'background', 'key' => 'bg_confetti', 'name' => 'Confetti Scatter', 'rarity' => 'common'],
            3 => ['slot' => 'hat', 'key' => 'hat_party', 'name' => 'Party Hat', 'rarity' => 'rare'],
            4 => ['slot' => 'badge', 'key' => 'badge_star', 'name' => 'Gold Star', 'rarity' => 'common'],
            5 => ['slot' => 'frame', 'key' => 'frame_scallop', 'name' => 'Scalloped Frame', 'rarity' => 'rare'],
            6 => ['slot' => 'accessory', 'key' => 'accessory_chain', 'name' => 'Chunky Chain', 'rarity' => 'rare'],
            7 => ['slot' => 'background', 'key' => 'bg_sunburst', 'name' => 'Sunburst', 'rarity' => 'rare'],
            8 => ['slot' => 'hat', 'key' => 'hat_crown', 'name' => 'Confetti Crown', 'rarity' => 'epic'],
            9 => ['slot' => 'badge', 'key' => 'badge_bolt', 'name' => 'Lightning Bolt', 'rarity' => 'rare'],
            10 => ['slot' => 'effect', 'key' => 'effect_sparkle', 'name' => 'Sparkle Ring', 'rarity' => 'epic'],
        ];

        $thresholds = config('seasons.tier_thresholds');

        foreach ($track as $tier => $row) {
            $cosmetic = Cosmetic::query()->firstOrCreate(
                ['key' => $row['key']],
                [...$row, 'source' => 'track', 'season_id' => $season->id, 'tier' => $tier],
            );

            // Battlepass ladder rows (free track only for Season 1).
            $season->tiers()->updateOrCreate(
                ['tier' => $tier],
                [
                    'xp_threshold' => (int) $thresholds[$tier - 1],
                    'free_cosmetic_id' => $cosmetic->id,
                    'premium_cosmetic_id' => null,
                ],
            );
        }

        Cosmetic::forgetCatalog();
    }
}
