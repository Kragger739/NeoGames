<?php

namespace Tests\Feature\Profile;

use App\Models\Friendship;
use App\Models\GameRoom;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class AccountDeletionTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_password_user_can_delete_their_account_and_cascaded_data(): void
    {
        Storage::fake('public');

        $user = User::factory()->create(['password' => Hash::make('password123')]);
        $friend = User::factory()->create();
        Friendship::create(['user_id' => $user->id, 'friend_id' => $friend->id, 'status' => 'accepted']);
        $room = GameRoom::factory()->create(['host_id' => $user->id]);

        $response = $this->actingAs($user)->deleteJson('/api/user', ['password' => 'password123']);

        $response->assertNoContent();
        $this->assertDatabaseMissing('users', ['id' => $user->id]);
        $this->assertDatabaseMissing('friendships', ['user_id' => $user->id]);
        $this->assertDatabaseMissing('game_rooms', ['id' => $room->id]);
        $this->assertDatabaseHas('users', ['id' => $friend->id]);
    }

    public function test_deletion_requires_the_correct_password(): void
    {
        $user = User::factory()->create(['password' => Hash::make('password123')]);

        $this->actingAs($user)->deleteJson('/api/user', ['password' => 'wrong-password'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('password');

        $this->assertDatabaseHas('users', ['id' => $user->id]);
    }

    public function test_an_oauth_user_confirms_deletion_with_their_username(): void
    {
        $user = User::factory()->create([
            'provider' => 'google',
            'provider_id' => 'g-123',
            'username' => 'oauthfan',
        ]);

        $this->actingAs($user)->deleteJson('/api/user', ['confirmation' => 'wrong'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('confirmation');

        $this->actingAs($user)->deleteJson('/api/user', ['confirmation' => 'oauthfan'])
            ->assertNoContent();

        $this->assertDatabaseMissing('users', ['id' => $user->id]);
    }

    public function test_the_avatar_file_is_removed_on_deletion(): void
    {
        Storage::fake('public');
        $user = User::factory()->create(['password' => Hash::make('password123')]);
        $path = 'avatars/pic.png';
        Storage::disk('public')->put($path, 'x');
        $user->update(['avatar_path' => $path]);

        $this->actingAs($user)->deleteJson('/api/user', ['password' => 'password123'])
            ->assertNoContent();

        Storage::disk('public')->assertMissing($path);
    }

    public function test_deletion_requires_authentication(): void
    {
        $this->deleteJson('/api/user')->assertUnauthorized();
    }
}
