<?php

namespace Tests\Feature\Workshop;

use App\Jobs\IngestYoutubeClip;
use App\Models\Dataset;
use App\Models\DubClip;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class DubPackTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('public');
    }

    private function video(): UploadedFile
    {
        return UploadedFile::fake()->create('clip.mp4', 400, 'video/mp4');
    }

    private function pack(User $owner, string $reviewStatus = 'draft'): Dataset
    {
        return $owner->datasets()->create([
            'name' => 'My pack',
            'type' => 'dub',
            'visibility' => 'private',
            'review_status' => $reviewStatus,
        ]);
    }

    public function test_creating_a_dub_pack_starts_as_a_draft(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)
            ->postJson('/api/datasets', ['name' => 'Movie bits', 'type' => 'dub'])
            ->assertCreated()
            ->assertJsonPath('type', 'dub')
            ->assertJsonPath('review_status', 'draft')
            ->assertJsonPath('visibility', 'private')
            ->assertJsonPath('dub_clips', []);
    }

    public function test_the_owner_can_add_a_clip_by_upload_and_publish_it(): void
    {
        $owner = User::factory()->create();
        $pack = $this->pack($owner);

        $this->actingAs($owner)
            ->post("/api/datasets/{$pack->id}/dub-clips", ['title' => 'Scene 1', 'video' => $this->video()])
            ->assertCreated()
            ->assertJsonCount(1, 'dub_clips')
            ->assertJsonPath('dub_clips.0.status', 'review')
            ->assertJsonPath('dub_clips.0.position', 1);

        $clip = $pack->dubClips()->firstOrFail();
        $this->assertSame($pack->id, $clip->dataset_id);
        $this->assertSame('workshop', $clip->source);
        Storage::disk('public')->assertExists($clip->source_video_path);

        // Author + publish the clip.
        $this->actingAs($owner)->putJson("/api/datasets/{$pack->id}/dub-clips/{$clip->id}/script", [
            'characters' => [['ref' => 'a', 'display_name' => 'X', 'color' => 'grape']],
            'lines' => [['character_ref' => 'a', 'start_ms' => 0, 'end_ms' => 1000, 'text' => 'go']],
        ])->assertOk();

        $this->actingAs($owner)->postJson("/api/datasets/{$pack->id}/dub-clips/{$clip->id}/publish")
            ->assertOk();
        $this->assertSame('ready', $clip->fresh()->status);
    }

    public function test_reorder_rewrites_clip_positions(): void
    {
        $owner = User::factory()->create();
        $pack = $this->pack($owner);
        $a = DubClip::create(['source' => 'workshop', 'dataset_id' => $pack->id, 'title' => 'A', 'status' => 'review', 'position' => 0]);
        $b = DubClip::create(['source' => 'workshop', 'dataset_id' => $pack->id, 'title' => 'B', 'status' => 'review', 'position' => 1]);

        $this->actingAs($owner)
            ->patchJson("/api/datasets/{$pack->id}/dub-clips/reorder", ['ids' => [$b->id, $a->id]])
            ->assertOk();

        $this->assertSame(0, $b->fresh()->position);
        $this->assertSame(1, $a->fresh()->position);
    }

    public function test_a_non_owner_cannot_manage_pack_clips_even_when_public(): void
    {
        $owner = User::factory()->create();
        $pack = $owner->datasets()->create(['name' => 'P', 'type' => 'dub', 'visibility' => 'public', 'review_status' => 'approved']);
        $stranger = User::factory()->create();

        $this->actingAs($stranger)
            ->post("/api/datasets/{$pack->id}/dub-clips", ['title' => 'nope', 'video' => $this->video()])
            ->assertForbidden();
    }

    public function test_dub_clip_routes_reject_a_ddf_dataset(): void
    {
        $owner = User::factory()->create();
        $ddf = $owner->datasets()->create(['name' => 'Q', 'type' => 'ddf', 'visibility' => 'private', 'language' => 'en']);

        $this->actingAs($owner)
            ->post("/api/datasets/{$ddf->id}/dub-clips", ['title' => 'nope', 'video' => $this->video()])
            ->assertStatus(422);
    }

    public function test_a_dub_pack_cannot_be_duplicated(): void
    {
        $owner = User::factory()->create();
        $pack = $this->pack($owner);

        $this->actingAs($owner)->postJson("/api/datasets/{$pack->id}/duplicate")->assertStatus(422);
    }

    public function test_adding_a_youtube_link_queues_the_ingest_job(): void
    {
        Queue::fake([IngestYoutubeClip::class]);
        $owner = User::factory()->create();
        $pack = $this->pack($owner);

        $this->actingAs($owner)
            ->postJson("/api/datasets/{$pack->id}/dub-clips/youtube", [
                'title' => 'From YT',
                'url' => 'https://youtu.be/abc123',
            ])
            ->assertCreated()
            ->assertJsonPath('dub_clips.0.status', 'processing');

        Queue::assertPushed(IngestYoutubeClip::class);
    }
}
