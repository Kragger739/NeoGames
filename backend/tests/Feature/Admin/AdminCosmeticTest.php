<?php

namespace Tests\Feature\Admin;

use App\Models\Cosmetic;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class AdminCosmeticTest extends TestCase
{
    use RefreshDatabase;

    private function admin(): User
    {
        return tap(User::factory()->create(), fn ($u) => $u->forceFill(['is_admin' => true])->save());
    }

    /** A genuine 1x1 PNG - no GD dependency (mirrors ProfileUpdateTest). */
    private function png(string $name = 'c.png'): UploadedFile
    {
        return UploadedFile::fake()->createWithContent($name, base64_decode(
            'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNkYAAAAAYAAjCB0C8AAAAASUVORK5CYII=',
        ));
    }

    public function test_non_admins_are_rejected(): void
    {
        $this->getJson('/api/admin/cosmetics')->assertUnauthorized();
        $this->actingAs(User::factory()->create())
            ->post('/api/admin/cosmetics', ['name' => 'X', 'slot' => 'frame', 'rarity' => 'common', 'source' => 'track'])
            ->assertForbidden();
    }

    public function test_create_with_an_uploaded_image(): void
    {
        Storage::fake('public');

        $res = $this->actingAs($this->admin())->post('/api/admin/cosmetics', [
            'name' => 'Neon Ring',
            'slot' => 'frame',
            'rarity' => 'epic',
            'source' => 'track',
            'image' => $this->png(),
        ])->assertCreated();

        $this->assertNotNull($res->json('image_url'));
        $this->assertFalse($res->json('has_registry_svg'));
        $this->assertSame('neon_ring', $res->json('key'));

        $cosmetic = Cosmetic::find($res->json('id'));
        Storage::disk('public')->assertExists($cosmetic->image_path);
    }

    public function test_create_without_an_image_is_a_registry_key_cosmetic(): void
    {
        $res = $this->actingAs($this->admin())->post('/api/admin/cosmetics', [
            'name' => 'Plain', 'slot' => 'badge', 'rarity' => 'common', 'source' => 'track',
        ])->assertCreated();

        $this->assertNull($res->json('image_url'));
        $this->assertTrue($res->json('has_registry_svg'));
    }

    public function test_keys_are_made_unique(): void
    {
        $admin = $this->admin();
        $this->actingAs($admin)->post('/api/admin/cosmetics', ['name' => 'Dup', 'slot' => 'hat', 'rarity' => 'common', 'source' => 'track'])
            ->assertJsonPath('key', 'dup');
        $this->actingAs($admin)->post('/api/admin/cosmetics', ['name' => 'Dup', 'slot' => 'hat', 'rarity' => 'common', 'source' => 'track'])
            ->assertJsonPath('key', 'dup_2');
    }

    public function test_update_swaps_the_image_and_deletes_the_old_file(): void
    {
        Storage::fake('public');
        $admin = $this->admin();

        $id = $this->actingAs($admin)->post('/api/admin/cosmetics', [
            'name' => 'Swap', 'slot' => 'frame', 'rarity' => 'common', 'source' => 'track', 'image' => $this->png('one.png'),
        ])->json('id');
        $oldPath = Cosmetic::find($id)->image_path;

        $this->actingAs($admin)->post("/api/admin/cosmetics/{$id}", [
            'name' => 'Swapped', 'slot' => 'frame', 'rarity' => 'rare', 'source' => 'track', 'image' => $this->png('two.png'),
        ])->assertOk()->assertJsonPath('name', 'Swapped');

        $newPath = Cosmetic::find($id)->image_path;
        $this->assertNotSame($oldPath, $newPath);
        Storage::disk('public')->assertMissing($oldPath);
        Storage::disk('public')->assertExists($newPath);
    }

    public function test_it_rejects_a_non_png_webp_upload(): void
    {
        Storage::fake('public');

        $this->actingAs($this->admin())->post('/api/admin/cosmetics', [
            'name' => 'Bad', 'slot' => 'frame', 'rarity' => 'common', 'source' => 'track',
            'image' => UploadedFile::fake()->create('art.gif', 20),
        ])->assertUnprocessable()->assertJsonValidationErrors('image');
    }

    public function test_delete_removes_the_row_and_the_file(): void
    {
        Storage::fake('public');
        $admin = $this->admin();

        $id = $this->actingAs($admin)->post('/api/admin/cosmetics', [
            'name' => 'Gone', 'slot' => 'frame', 'rarity' => 'common', 'source' => 'track', 'image' => $this->png(),
        ])->json('id');
        $path = Cosmetic::find($id)->image_path;

        $this->actingAs($admin)->deleteJson("/api/admin/cosmetics/{$id}")->assertNoContent();

        $this->assertDatabaseMissing('cosmetics', ['id' => $id]);
        Storage::disk('public')->assertMissing($path);
    }
}
