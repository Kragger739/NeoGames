<?php

namespace Tests\Feature\Cosmetics;

use App\Models\Cosmetic;
use App\Models\User;
use Database\Seeders\SeasonSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

class CosmeticsEquipTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();
        $this->seed(SeasonSeeder::class);
    }

    public function test_wardrobe_returns_catalog_ownership_and_the_tier_ladder(): void
    {
        $response = $this->actingAs(User::factory()->create())->getJson('/api/cosmetics');

        $response->assertOk();
        $response->assertJsonPath('season.slug', 'season-1');

        $catalog = collect($response->json('catalog'));
        $this->assertTrue($catalog->firstWhere('key', 'frame_soft')['owned'], 'starter is owned');
        $this->assertFalse($catalog->firstWhere('key', 'hat_party')['owned'], 'a track item is locked');
        $this->assertCount(10, $response->json('tiers'));
        $this->assertSame(0, $response->json('progress.current_tier'));
    }

    public function test_a_host_can_equip_a_starter_cosmetic(): void
    {
        $user = User::factory()->create();
        $frame = Cosmetic::where('key', 'frame_soft')->firstOrFail();

        $response = $this->actingAs($user)->patchJson('/api/profile/cosmetics', [
            'equipped' => ['frame' => $frame->id],
        ]);

        $response->assertOk();
        $response->assertJsonPath('avatar.cosmetics.frame.key', 'frame_soft');
        $this->assertSame(['frame' => $frame->id], $user->fresh()->equipped_cosmetics);
    }

    public function test_equipping_an_unowned_cosmetic_is_rejected(): void
    {
        $locked = Cosmetic::where('key', 'hat_party')->firstOrFail();

        $this->actingAs(User::factory()->create())
            ->patchJson('/api/profile/cosmetics', ['equipped' => ['hat' => $locked->id]])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('equipped.hat');
    }

    public function test_equipping_in_the_wrong_slot_is_rejected(): void
    {
        $frame = Cosmetic::where('key', 'frame_soft')->firstOrFail();

        $this->actingAs(User::factory()->create())
            ->patchJson('/api/profile/cosmetics', ['equipped' => ['hat' => $frame->id]])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('equipped.hat');
    }

    public function test_null_clears_a_slot(): void
    {
        $user = User::factory()->create();
        $frame = Cosmetic::where('key', 'frame_soft')->firstOrFail();
        $user->update(['equipped_cosmetics' => ['frame' => $frame->id]]);

        $this->actingAs($user)
            ->patchJson('/api/profile/cosmetics', ['equipped' => ['frame' => null]])
            ->assertOk();

        $this->assertNull($user->fresh()->equipped_cosmetics);
    }

    public function test_an_unknown_slot_is_rejected(): void
    {
        $this->actingAs(User::factory()->create())
            ->patchJson('/api/profile/cosmetics', ['equipped' => ['wings' => 1]])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('equipped.wings');
    }

    public function test_cosmetics_endpoints_require_authentication(): void
    {
        $this->getJson('/api/cosmetics')->assertUnauthorized();
        $this->patchJson('/api/profile/cosmetics', ['equipped' => []])->assertUnauthorized();
    }
}
