<?php

namespace Tests\Feature\Profile;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class ProfileUpdateTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_host_can_change_their_username(): void
    {
        $host = User::factory()->create(['username' => 'oldname']);

        $response = $this->actingAs($host)->patchJson('/api/profile', ['username' => 'newname']);

        $response->assertOk();
        $response->assertJsonPath('username', 'newname');
        $this->assertDatabaseHas('users', ['id' => $host->id, 'username' => 'newname']);
    }

    public function test_username_must_be_unique(): void
    {
        User::factory()->create(['username' => 'taken']);
        $host = User::factory()->create(['username' => 'mine']);

        $response = $this->actingAs($host)->patchJson('/api/profile', ['username' => 'taken']);

        $response->assertUnprocessable();
        $response->assertJsonValidationErrors('username');
    }

    public function test_a_host_can_keep_their_own_current_username(): void
    {
        $host = User::factory()->create(['username' => 'mine']);

        $response = $this->actingAs($host)->patchJson('/api/profile', ['username' => 'mine']);

        $response->assertOk();
    }

    public function test_profile_update_requires_authentication(): void
    {
        $this->patchJson('/api/profile', ['username' => 'nope'])->assertUnauthorized();
    }

    /**
     * A real, minimal (1x1) PNG's raw bytes - UploadedFile::fake()->image()
     * needs the GD extension to generate one, which isn't installed in
     * every dev environment; this exercises the exact same
     * getimagesize()-backed 'image' validation with genuinely valid image
     * data, without that dependency.
     */
    private function fakeImageFile(string $name = 'me.png'): UploadedFile
    {
        $onePixelPng = base64_decode(
            'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNkYAAAAAYAAjCB0C8AAAAASUVORK5CYII=',
        );

        return UploadedFile::fake()->createWithContent($name, $onePixelPng);
    }

    public function test_a_host_can_upload_a_profile_picture(): void
    {
        Storage::fake('public');
        $host = User::factory()->create();

        $response = $this->actingAs($host)->postJson('/api/profile/avatar', [
            'avatar' => $this->fakeImageFile(),
        ]);

        $response->assertOk();
        $this->assertNotNull($response->json('avatar_url'));

        $host->refresh();
        $this->assertNotNull($host->avatar_path);
        Storage::disk('public')->assertExists($host->avatar_path);
    }

    /**
     * A string of re-uploads shouldn't leak orphaned files on the disk
     * forever - the old one is deleted the moment a new one replaces it.
     */
    public function test_uploading_a_new_avatar_replaces_and_deletes_the_old_one(): void
    {
        Storage::fake('public');
        $host = User::factory()->create();

        $this->actingAs($host)->postJson('/api/profile/avatar', [
            'avatar' => $this->fakeImageFile('first.png'),
        ])->assertOk();
        $oldPath = $host->refresh()->avatar_path;

        $this->actingAs($host)->postJson('/api/profile/avatar', [
            'avatar' => $this->fakeImageFile('second.png'),
        ])->assertOk();
        $newPath = $host->refresh()->avatar_path;

        $this->assertNotSame($oldPath, $newPath);
        Storage::disk('public')->assertMissing($oldPath);
        Storage::disk('public')->assertExists($newPath);
    }

    public function test_avatar_upload_rejects_a_non_image_file(): void
    {
        Storage::fake('public');
        $host = User::factory()->create();

        $response = $this->actingAs($host)->postJson('/api/profile/avatar', [
            'avatar' => UploadedFile::fake()->create('notes.txt', 10),
        ]);

        $response->assertUnprocessable();
        $response->assertJsonValidationErrors('avatar');
    }

    public function test_avatar_upload_rejects_a_file_over_the_size_cap(): void
    {
        Storage::fake('public');
        $host = User::factory()->create();

        // Doesn't need to be a genuinely valid image here - oversized is
        // enough on its own to fail validation on 'avatar' either way.
        $response = $this->actingAs($host)->postJson('/api/profile/avatar', [
            'avatar' => UploadedFile::fake()->create('huge.jpg', 3000),
        ]);

        $response->assertUnprocessable();
        $response->assertJsonValidationErrors('avatar');
    }

    public function test_a_host_can_remove_their_profile_picture(): void
    {
        Storage::fake('public');
        $host = User::factory()->create();

        $this->actingAs($host)->postJson('/api/profile/avatar', [
            'avatar' => $this->fakeImageFile(),
        ])->assertOk();
        $path = $host->refresh()->avatar_path;

        $response = $this->actingAs($host)->deleteJson('/api/profile/avatar');

        $response->assertOk();
        $this->assertNull($response->json('avatar_url'));
        $this->assertNull($host->refresh()->avatar_path);
        Storage::disk('public')->assertMissing($path);
    }

    public function test_removing_an_avatar_that_was_never_set_is_a_quiet_no_op(): void
    {
        $host = User::factory()->create();

        $response = $this->actingAs($host)->deleteJson('/api/profile/avatar');

        $response->assertOk();
        $this->assertNull($response->json('avatar_url'));
    }

    public function test_avatar_endpoints_require_authentication(): void
    {
        $this->postJson('/api/profile/avatar', [])->assertUnauthorized();
        $this->deleteJson('/api/profile/avatar')->assertUnauthorized();
    }
}
