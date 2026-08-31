<?php

namespace Tests\Feature\Cosmetics;

use App\Models\Cosmetic;
use App\Models\Season;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

class CosmeticImageTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();
    }

    public function test_an_uploaded_cosmetic_surfaces_its_image_url_in_the_avatar_payload(): void
    {
        $uploaded = Cosmetic::create([
            'slot' => 'frame', 'key' => 'custom_frame', 'name' => 'Custom',
            'rarity' => 'epic', 'source' => 'track', 'image_path' => 'cosmetics/abc.png',
        ]);
        $registry = Cosmetic::create([
            'slot' => 'badge', 'key' => 'badge_dot', 'name' => 'Dot',
            'rarity' => 'common', 'source' => 'starter',
        ]);

        $user = User::factory()->create([
            'equipped_cosmetics' => ['frame' => $uploaded->id, 'badge' => $registry->id],
        ]);

        $res = $this->actingAs($user)->getJson('/api/user')->assertOk();

        $this->assertStringContainsString('/storage/cosmetics/abc.png', $res->json('avatar.cosmetics.frame.image_url'));
        $this->assertSame('custom_frame', $res->json('avatar.cosmetics.frame.key'));
        $this->assertNull($res->json('avatar.cosmetics.badge.image_url'));
    }

    public function test_the_cosmetics_endpoint_includes_image_url_in_catalog_and_ladder(): void
    {
        $season = Season::create([
            'name' => 'S', 'slug' => 's',
            'starts_at' => now()->subDay(), 'ends_at' => now()->addDays(30),
        ]);
        $uploaded = Cosmetic::create([
            'slot' => 'frame', 'key' => 'custom_frame', 'name' => 'Custom',
            'rarity' => 'epic', 'source' => 'track', 'season_id' => $season->id,
            'image_path' => 'cosmetics/abc.png',
        ]);
        $season->tiers()->create(['tier' => 1, 'xp_threshold' => 50, 'free_cosmetic_id' => $uploaded->id]);

        $user = User::factory()->create();

        $res = $this->actingAs($user)->getJson('/api/cosmetics')->assertOk();

        $catalogEntry = collect($res->json('catalog'))->firstWhere('id', $uploaded->id);
        $this->assertStringContainsString('/storage/cosmetics/abc.png', $catalogEntry['image_url']);

        $this->assertStringContainsString('/storage/cosmetics/abc.png', $res->json('tiers.0.free.image_url'));
        $this->assertFalse($res->json('tiers.0.free_owned'));
        $this->assertFalse($res->json('progress.has_pass'));
    }
}
