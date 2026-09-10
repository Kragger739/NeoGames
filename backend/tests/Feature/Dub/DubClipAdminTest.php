<?php

namespace Tests\Feature\Dub;

use App\Models\DubClip;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class DubClipAdminTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('public');
    }

    private function admin(): User
    {
        return tap(User::factory()->create(), fn ($u) => $u->forceFill(['is_admin' => true])->save());
    }

    private function video(string $name = 'clip.mp4'): UploadedFile
    {
        return UploadedFile::fake()->create($name, 600, 'video/mp4');
    }

    public function test_the_clip_library_is_gated_to_admins(): void
    {
        $this->getJson('/api/admin/dub-clips')->assertUnauthorized();

        $this->actingAs(User::factory()->create())
            ->postJson('/api/admin/dub-clips', ['title' => 'Nope'])
            ->assertForbidden();
    }

    public function test_uploading_a_video_creates_a_review_library_clip(): void
    {
        $response = $this->actingAs($this->admin())
            ->post('/api/admin/dub-clips', ['title' => 'Diner scene', 'video' => $this->video()]);

        $response->assertCreated()
            ->assertJsonPath('status', 'review')
            ->assertJsonPath('source', 'library')
            ->assertJsonPath('is_public', false)
            ->assertJsonPath('character_count', 0)
            ->assertJsonPath('line_count', 0);

        $clip = DubClip::firstOrFail();
        $this->assertNotNull($clip->source_video_path);
        Storage::disk('public')->assertExists($clip->source_video_path);
        $this->assertStringStartsWith("dub/clips/{$clip->id}/", $clip->source_video_path);
    }

    public function test_a_non_video_upload_is_rejected(): void
    {
        $this->actingAs($this->admin())
            ->post('/api/admin/dub-clips', [
                'title' => 'Bad',
                'video' => UploadedFile::fake()->create('notes.txt', 10, 'text/plain'),
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors('video');
    }

    public function test_put_script_replaces_characters_and_lines(): void
    {
        $admin = $this->admin();
        $clip = $this->makeClip($admin);

        $payload = [
            'characters' => [
                ['ref' => 'tmp-0', 'display_name' => 'Detective', 'color' => 'grape'],
                ['ref' => 'tmp-1', 'display_name' => 'Witness', 'color' => 'turquoise'],
            ],
            'lines' => [
                ['character_ref' => 'tmp-0', 'start_ms' => 0, 'end_ms' => 1500, 'text' => 'Where were you?'],
                ['character_ref' => 'tmp-1', 'start_ms' => 1600, 'end_ms' => 3000, 'text' => 'At home.'],
                ['character_ref' => 'tmp-0', 'start_ms' => 3100, 'end_ms' => 4200, 'text' => 'All night?'],
            ],
        ];

        $this->actingAs($admin)
            ->putJson("/api/admin/dub-clips/{$clip->id}/script", $payload)
            ->assertOk()
            ->assertJsonCount(2, 'characters')
            ->assertJsonCount(3, 'lines')
            ->assertJsonPath('lines.0.position', 0)
            ->assertJsonPath('lines.2.position', 2)
            ->assertJsonPath('duration_ms', 4200);

        $clip->refresh();
        $this->assertSame(2, $clip->characters()->count());
        $this->assertSame(3, $clip->lines()->count());
        $firstLineCharId = $clip->lines()->where('position', 0)->value('dub_clip_character_id');
        $this->assertSame('Detective', $clip->characters()->find($firstLineCharId)->display_name);

        // A second PUT fully replaces (no leftover rows).
        $this->actingAs($admin)
            ->putJson("/api/admin/dub-clips/{$clip->id}/script", [
                'characters' => [['ref' => 'a', 'display_name' => 'Solo', 'color' => 'coral']],
                'lines' => [['character_ref' => 'a', 'start_ms' => 0, 'end_ms' => 900, 'text' => 'Hi']],
            ])
            ->assertOk()
            ->assertJsonCount(1, 'characters')
            ->assertJsonCount(1, 'lines');
        $this->assertSame(1, $clip->fresh()->characters()->count());
        $this->assertSame(1, $clip->fresh()->lines()->count());
    }

    public function test_put_script_rejects_a_line_that_ends_before_it_starts(): void
    {
        $admin = $this->admin();
        $clip = $this->makeClip($admin);

        $this->actingAs($admin)
            ->putJson("/api/admin/dub-clips/{$clip->id}/script", [
                'characters' => [['ref' => 'a', 'display_name' => 'X', 'color' => 'grape']],
                'lines' => [['character_ref' => 'a', 'start_ms' => 2000, 'end_ms' => 1000, 'text' => 'bad']],
            ])
            ->assertStatus(422);
    }

    public function test_put_script_is_blocked_once_the_clip_is_published(): void
    {
        $admin = $this->admin();
        $clip = $this->makeClip($admin, status: 'ready');

        $this->actingAs($admin)
            ->putJson("/api/admin/dub-clips/{$clip->id}/script", ['characters' => [], 'lines' => []])
            ->assertStatus(422)
            ->assertJsonValidationErrors('clip');
    }

    public function test_publish_requires_a_video_characters_and_lines(): void
    {
        $admin = $this->admin();
        $clip = $this->makeClip($admin);

        // Nothing authored yet.
        $this->actingAs($admin)->postJson("/api/admin/dub-clips/{$clip->id}/publish")->assertStatus(422);

        $this->actingAs($admin)->putJson("/api/admin/dub-clips/{$clip->id}/script", [
            'characters' => [['ref' => 'a', 'display_name' => 'X', 'color' => 'grape']],
            'lines' => [['character_ref' => 'a', 'start_ms' => 0, 'end_ms' => 1200, 'text' => 'go']],
        ])->assertOk();

        $this->actingAs($admin)->postJson("/api/admin/dub-clips/{$clip->id}/publish")
            ->assertOk()
            ->assertJsonPath('status', 'ready');

        $this->assertSame('ready', $clip->fresh()->status);
        $this->assertSame(1200, $clip->fresh()->duration_ms);
    }

    public function test_unpublish_returns_a_clip_to_review(): void
    {
        $admin = $this->admin();
        $clip = $this->makeClip($admin, status: 'ready');

        $this->actingAs($admin)->postJson("/api/admin/dub-clips/{$clip->id}/unpublish")
            ->assertOk()
            ->assertJsonPath('status', 'review');
    }

    public function test_updating_swaps_the_video_and_deletes_the_old_file(): void
    {
        $admin = $this->admin();
        $clip = $this->makeClip($admin);
        $oldPath = $clip->source_video_path;
        Storage::disk('public')->assertExists($oldPath);

        $this->actingAs($admin)
            ->post("/api/admin/dub-clips/{$clip->id}", ['title' => 'Renamed', 'is_public' => '1', 'video' => $this->video('new.mp4')])
            ->assertOk()
            ->assertJsonPath('title', 'Renamed')
            ->assertJsonPath('is_public', true);

        Storage::disk('public')->assertMissing($oldPath);
        Storage::disk('public')->assertExists($clip->fresh()->source_video_path);
    }

    public function test_deleting_removes_the_row_the_file_and_the_child_rows(): void
    {
        $admin = $this->admin();
        $clip = $this->makeClip($admin);
        $this->actingAs($admin)->putJson("/api/admin/dub-clips/{$clip->id}/script", [
            'characters' => [['ref' => 'a', 'display_name' => 'X', 'color' => 'grape']],
            'lines' => [['character_ref' => 'a', 'start_ms' => 0, 'end_ms' => 1000, 'text' => 'x']],
        ])->assertOk();
        $path = $clip->source_video_path;

        $this->actingAs($admin)->deleteJson("/api/admin/dub-clips/{$clip->id}")->assertNoContent();

        $this->assertDatabaseMissing('dub_clips', ['id' => $clip->id]);
        $this->assertDatabaseMissing('dub_clip_characters', ['dub_clip_id' => $clip->id]);
        $this->assertDatabaseMissing('dub_clip_lines', ['dub_clip_id' => $clip->id]);
        Storage::disk('public')->assertMissing($path);
    }

    public function test_a_published_public_clip_shows_in_the_lobby_picker_but_a_review_one_does_not(): void
    {
        $admin = $this->admin();
        $reviewClip = $this->makeClip($admin);
        $liveClip = $this->makeClip($admin);

        foreach ([$reviewClip, $liveClip] as $clip) {
            $this->actingAs($admin)->putJson("/api/admin/dub-clips/{$clip->id}/script", [
                'characters' => [['ref' => 'a', 'display_name' => 'X', 'color' => 'grape']],
                'lines' => [['character_ref' => 'a', 'start_ms' => 0, 'end_ms' => 1000, 'text' => 'x']],
            ])->assertOk();
        }

        $this->actingAs($admin)->postJson("/api/admin/dub-clips/{$liveClip->id}/publish")->assertOk();
        $this->actingAs($admin)->post("/api/admin/dub-clips/{$liveClip->id}", ['is_public' => '1'])->assertOk();

        $picker = $this->getJson('/api/dub-clips')->assertOk()->json('clips');
        $ids = array_column($picker, 'id');

        $this->assertContains($liveClip->id, $ids);
        $this->assertNotContains($reviewClip->id, $ids);
    }

    private function makeClip(User $admin, string $status = 'review'): DubClip
    {
        $clip = $this->actingAs($admin)
            ->post('/api/admin/dub-clips', ['title' => 'Scene '.uniqid(), 'video' => $this->video()])
            ->assertCreated();

        $model = DubClip::findOrFail($clip->json('id'));

        if ($status !== 'review') {
            $model->forceFill(['status' => $status])->save();
        }

        return $model->fresh();
    }
}
