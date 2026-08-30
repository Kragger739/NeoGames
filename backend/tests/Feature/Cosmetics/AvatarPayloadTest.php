<?php

namespace Tests\Feature\Cosmetics;

use App\Models\Cosmetic;
use App\Models\GameRoom;
use App\Models\RoomPlayer;
use App\Models\Season;
use App\Models\SeasonProgress;
use App\Models\User;
use Database\Seeders\SeasonSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

class AvatarPayloadTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();
    }

    public function test_api_user_carries_an_avatar_payload(): void
    {
        $user = User::factory()->create(['xp' => 300]);

        $response = $this->actingAs($user)->getJson('/api/user');

        $response->assertOk();
        $response->assertJsonPath('avatar.level', 3);
        $response->assertJsonPath('avatar.avatar_url', null);
        $this->assertSame([], $response->json('avatar.cosmetics'));
    }

    public function test_equipped_cosmetics_resolve_to_key_and_rarity(): void
    {
        $this->seed(SeasonSeeder::class);
        $user = User::factory()->create();
        $frame = Cosmetic::where('key', 'frame_soft')->firstOrFail();
        $user->update(['equipped_cosmetics' => ['frame' => $frame->id]]);

        $response = $this->actingAs($user)->getJson('/api/user');

        $response->assertJsonPath('avatar.cosmetics.frame.key', 'frame_soft');
        $response->assertJsonPath('avatar.cosmetics.frame.rarity', 'common');
    }

    public function test_stale_or_unknown_equipped_ids_are_dropped(): void
    {
        $user = User::factory()->create();
        $user->update(['equipped_cosmetics' => ['frame' => 99999, 'hat' => 12345]]);

        $response = $this->actingAs($user)->getJson('/api/user');

        $this->assertSame([], $response->json('avatar.cosmetics'));
    }

    public function test_room_present_players_carry_an_avatar_payload(): void
    {
        $this->seed(SeasonSeeder::class);
        $host = User::factory()->create();
        $frame = Cosmetic::where('key', 'frame_soft')->firstOrFail();
        $host->update(['equipped_cosmetics' => ['frame' => $frame->id]]);

        $room = GameRoom::factory()->for($host, 'host')->create();
        $room->players()->create([
            'user_id' => $host->id,
            'nickname' => 'Host',
            'connection_token' => RoomPlayer::generateConnectionToken(),
        ]);

        $response = $this->getJson("/api/rooms/{$room->code}");

        $response->assertOk();
        $response->assertJsonPath('players.0.avatar.cosmetics.frame.key', 'frame_soft');
        $response->assertJsonPath('players.0.avatar.level', 1);
    }

    public function test_leaderboard_entry_avatar_carries_the_admin_flag(): void
    {
        $season = Season::create([
            'name' => 'Season 1', 'slug' => 's1',
            'starts_at' => now()->subDay(), 'ends_at' => now()->addDays(30),
        ]);
        $admin = User::factory()->create(['username' => 'adminuser']);
        $admin->forceFill(['is_admin' => true])->save();
        SeasonProgress::create(['season_id' => $season->id, 'user_id' => $admin->id, 'xp' => 500]);

        $this->actingAs($admin)->getJson('/api/leaderboard')
            ->assertOk()
            ->assertJsonPath('entries.0.avatar.is_admin', true);
    }

    public function test_room_player_avatar_carries_the_admin_flag(): void
    {
        $host = User::factory()->create();
        $host->forceFill(['is_admin' => true])->save();

        $room = GameRoom::factory()->for($host, 'host')->create();
        $room->players()->create([
            'user_id' => $host->id,
            'nickname' => 'Host',
            'connection_token' => RoomPlayer::generateConnectionToken(),
        ]);

        $this->getJson("/api/rooms/{$room->code}")
            ->assertOk()
            ->assertJsonPath('players.0.avatar.is_admin', true);
    }

    public function test_avatar_payload_includes_the_admin_flag(): void
    {
        $admin = User::factory()->create();
        $admin->forceFill(['is_admin' => true])->save();
        $plain = User::factory()->create();

        $this->actingAs($admin)->getJson('/api/user')
            ->assertOk()
            ->assertJsonPath('avatar.is_admin', true);

        $this->actingAs($plain)->getJson('/api/user')
            ->assertOk()
            ->assertJsonPath('avatar.is_admin', false);
    }

    public function test_is_banned_reflects_the_column(): void
    {
        $user = User::factory()->create();
        $this->assertFalse($user->isBanned());

        $user->forceFill(['banned_at' => now()])->save();
        $this->assertTrue($user->fresh()->isBanned());
    }
}
