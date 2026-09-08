<?php

namespace Tests\Feature\Admin;

use App\Jobs\FetchIconicArtistCatalogue;
use App\Models\IconicArtist;
use App\Models\Song;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class AdminIconicArtistTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        // startCatalogueFetch() dispatches this on save - keep it out of the
        // request path so the tests don't reach the real iTunes API.
        Queue::fake([FetchIconicArtistCatalogue::class]);
    }

    private function admin(): User
    {
        return tap(User::factory()->create(), fn ($u) => $u->forceFill(['is_admin' => true])->save());
    }

    private function png(string $name = 'a.png'): UploadedFile
    {
        return UploadedFile::fake()->createWithContent($name, base64_decode(
            'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNkYAAAAAYAAjCB0C8AAAAASUVORK5CYII=',
        ));
    }

    public function test_it_is_gated_to_admins(): void
    {
        $this->getJson('/api/admin/iconic-artists')->assertUnauthorized();
        $this->actingAs(User::factory()->create())
            ->post('/api/admin/iconic-artists', ['name' => 'Queen'])
            ->assertForbidden();
    }

    public function test_create_with_a_photo_and_pool_count(): void
    {
        Storage::fake('public');
        Song::factory()->count(3)->create(['artist' => 'Queen', 'excluded' => false]);
        Song::factory()->create(['artist' => 'Queen', 'excluded' => true]);

        $res = $this->actingAs($this->admin())->post('/api/admin/iconic-artists', [
            'name' => 'Queen',
            'enabled' => '1',
            'sort_order' => '2',
            'image' => $this->png(),
        ])->assertCreated();

        $res->assertJsonPath('name', 'Queen')
            ->assertJsonPath('enabled', true)
            ->assertJsonPath('sort_order', 2)
            ->assertJsonPath('pool_size', 3);          // excluded song not counted
        $this->assertNotNull($res->json('image_url'));

        Storage::disk('public')->assertExists(IconicArtist::find($res->json('id'))->image_path);
    }

    public function test_update_swaps_the_photo_and_deletes_the_old_file(): void
    {
        Storage::fake('public');
        $admin = $this->admin();

        $id = $this->actingAs($admin)->post('/api/admin/iconic-artists', [
            'name' => 'Abba', 'image' => $this->png('one.png'),
        ])->json('id');
        $oldPath = IconicArtist::find($id)->image_path;

        $this->actingAs($admin)->post("/api/admin/iconic-artists/{$id}", [
            'name' => 'ABBA', 'enabled' => '0', 'image' => $this->png('two.png'),
        ])->assertOk()->assertJsonPath('name', 'ABBA')->assertJsonPath('enabled', false);

        $newPath = IconicArtist::find($id)->image_path;
        $this->assertNotSame($oldPath, $newPath);
        Storage::disk('public')->assertMissing($oldPath);
        Storage::disk('public')->assertExists($newPath);
    }

    public function test_it_rejects_a_non_image_upload(): void
    {
        Storage::fake('public');

        $this->actingAs($this->admin())->post('/api/admin/iconic-artists', [
            'name' => 'Bad', 'image' => UploadedFile::fake()->create('art.txt', 10),
        ])->assertUnprocessable()->assertJsonValidationErrors('image');
    }

    public function test_delete_removes_the_row_and_the_file(): void
    {
        Storage::fake('public');
        $admin = $this->admin();

        $id = $this->actingAs($admin)->post('/api/admin/iconic-artists', [
            'name' => 'Gone', 'image' => $this->png(),
        ])->json('id');
        $path = IconicArtist::find($id)->image_path;

        $this->actingAs($admin)->deleteJson("/api/admin/iconic-artists/{$id}")->assertNoContent();

        $this->assertDatabaseMissing('iconic_artists', ['id' => $id]);
        Storage::disk('public')->assertMissing($path);
    }

    public function test_it_extracts_the_apple_artist_id_from_a_link(): void
    {
        $res = $this->actingAs($this->admin())->post('/api/admin/iconic-artists', [
            'name' => 'Taylor Swift',
            'apple_artist' => 'https://music.apple.com/us/artist/taylor-swift/159260351?l=en',
        ])->assertCreated();

        $res->assertJsonPath('apple_artist_id', 159260351);
        $this->assertDatabaseHas('iconic_artists', [
            'id' => $res->json('id'), 'apple_artist_id' => 159260351,
        ]);
    }

    public function test_it_accepts_a_bare_numeric_id(): void
    {
        $this->actingAs($this->admin())->post('/api/admin/iconic-artists', [
            'name' => 'Queen', 'apple_artist' => '3296287',
        ])->assertCreated()->assertJsonPath('apple_artist_id', 3296287);
    }

    public function test_it_rejects_a_link_with_no_id(): void
    {
        $this->actingAs($this->admin())->post('/api/admin/iconic-artists', [
            'name' => 'Nope', 'apple_artist' => 'https://example.com/artist/someone',
        ])->assertUnprocessable()->assertJsonValidationErrors('apple_artist');
    }

    public function test_editing_without_the_field_keeps_the_pin(): void
    {
        $id = IconicArtist::factory()->create(['apple_artist_id' => 12345])->id;

        $this->actingAs($this->admin())->post("/api/admin/iconic-artists/{$id}", [
            'name' => 'Renamed',
        ])->assertOk()->assertJsonPath('apple_artist_id', 12345);
    }

    public function test_editing_with_a_blank_field_clears_the_pin(): void
    {
        $id = IconicArtist::factory()->create(['apple_artist_id' => 12345])->id;

        $this->actingAs($this->admin())->post("/api/admin/iconic-artists/{$id}", [
            'name' => 'Kept', 'apple_artist' => '',
        ])->assertOk()->assertJsonPath('apple_artist_id', null);
    }

    public function test_changing_the_pin_re_kicks_the_catalogue_fetch(): void
    {
        $id = IconicArtist::factory()->create(['name' => 'Same', 'apple_artist_id' => 111])->id;
        Queue::fake([FetchIconicArtistCatalogue::class]);

        $this->actingAs($this->admin())->post("/api/admin/iconic-artists/{$id}", [
            'name' => 'Same', 'apple_artist' => '222',
        ])->assertOk();

        Queue::assertPushed(FetchIconicArtistCatalogue::class);
    }

    public function test_index_is_ordered_by_sort_order(): void
    {
        IconicArtist::factory()->create(['name' => 'Third', 'sort_order' => 30]);
        IconicArtist::factory()->create(['name' => 'First', 'sort_order' => 10]);
        IconicArtist::factory()->create(['name' => 'Second', 'sort_order' => 20]);

        $this->actingAs($this->admin())->getJson('/api/admin/iconic-artists')
            ->assertOk()
            ->assertJsonPath('artists.0.name', 'First')
            ->assertJsonPath('artists.2.name', 'Third');
    }
}
