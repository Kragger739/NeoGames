<?php

namespace Tests\Feature\Dub;

use App\Jobs\IngestYoutubeClip;
use App\Models\DubClip;
use App\Models\User;
use App\Services\Dub\YoutubeDownloader;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use RuntimeException;
use Tests\TestCase;

class DubYoutubeIngestTest extends TestCase
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

    /** A YoutubeDownloader that writes a stub file instead of spawning yt-dlp. */
    private function fakeDownloader(): void
    {
        $this->app->bind(YoutubeDownloader::class, fn () => new class extends YoutubeDownloader
        {
            public function download(string $url, string $workDir): array
            {
                $path = rtrim($workDir, '/').'/source.mp4';
                file_put_contents($path, 'stub mp4');

                return ['path' => $path, 'duration_ms' => 12000, 'width' => 1280, 'height' => 720];
            }
        });
    }

    private function failingDownloader(string $message): void
    {
        $this->app->bind(YoutubeDownloader::class, fn () => new class($message) extends YoutubeDownloader
        {
            public function __construct(private string $msg) {}

            public function download(string $url, string $workDir): array
            {
                throw new RuntimeException($this->msg);
            }
        });
    }

    public function test_a_non_youtube_url_is_rejected(): void
    {
        $this->actingAs($this->admin())
            ->postJson('/api/admin/dub-clips/youtube', ['title' => 'X', 'url' => 'https://example.com/nope'])
            ->assertStatus(422)
            ->assertJsonValidationErrors('url');
    }

    public function test_adding_a_youtube_link_creates_a_processing_clip_and_queues_the_job(): void
    {
        Queue::fake([IngestYoutubeClip::class]);

        $response = $this->actingAs($this->admin())
            ->postJson('/api/admin/dub-clips/youtube', [
                'title' => 'Courtroom bit',
                'url' => 'https://www.youtube.com/watch?v=abc123',
            ])
            ->assertCreated()
            ->assertJsonPath('status', 'processing')
            ->assertJsonPath('source_url', 'https://www.youtube.com/watch?v=abc123');

        $clip = DubClip::firstOrFail();
        $this->assertSame('processing', $clip->status);
        Queue::assertPushed(IngestYoutubeClip::class, fn ($job) => $job->clipId === $clip->id);
        $this->assertSame($response->json('id'), $clip->id);
    }

    public function test_the_ingest_job_downloads_and_moves_the_clip_to_review(): void
    {
        $this->fakeDownloader();

        $clip = DubClip::create([
            'source' => 'library',
            'title' => 'Ingest me',
            'status' => 'processing',
            'source_url' => 'https://youtu.be/abc123',
        ]);

        (new IngestYoutubeClip($clip->id))->handle(app(YoutubeDownloader::class));

        $clip->refresh();
        $this->assertSame('review', $clip->status);
        $this->assertSame('dub/clips/'.$clip->id.'/source.mp4', $clip->source_video_path);
        Storage::disk('public')->assertExists($clip->source_video_path);
        $this->assertSame(12000, $clip->duration_ms);
        $this->assertSame(1280, $clip->video_width);
        $this->assertNull($clip->processing_error);
        $this->assertNotNull($clip->processed_at);
    }

    public function test_a_failed_download_marks_the_clip_failed_with_an_error(): void
    {
        $this->failingDownloader('That video is 4000s long; the limit is 900s.');

        $clip = DubClip::create([
            'source' => 'library',
            'title' => 'Too long',
            'status' => 'processing',
            'source_url' => 'https://youtu.be/toolong',
        ]);

        (new IngestYoutubeClip($clip->id))->handle(app(YoutubeDownloader::class));

        $clip->refresh();
        $this->assertSame('failed', $clip->status);
        $this->assertStringContainsString('the limit is 900s', $clip->processing_error);
        $this->assertNull($clip->source_video_path);
    }

    public function test_reingest_requeues_a_failed_link_clip(): void
    {
        Queue::fake([IngestYoutubeClip::class]);

        $clip = DubClip::create([
            'source' => 'library',
            'title' => 'Retry me',
            'status' => 'failed',
            'processing_error' => 'network blip',
            'source_url' => 'https://youtu.be/retry',
        ]);

        $this->actingAs($this->admin())
            ->postJson("/api/admin/dub-clips/{$clip->id}/reingest")
            ->assertOk()
            ->assertJsonPath('status', 'processing');

        $this->assertSame('processing', $clip->fresh()->status);
        $this->assertNull($clip->fresh()->processing_error);
        Queue::assertPushed(IngestYoutubeClip::class);
    }

    public function test_reingest_rejects_a_clip_that_was_not_imported_from_a_link(): void
    {
        $clip = DubClip::create([
            'source' => 'library',
            'title' => 'Uploaded',
            'status' => 'review',
            'source_video_path' => 'dub/clips/x/source.mp4',
        ]);

        $this->actingAs($this->admin())
            ->postJson("/api/admin/dub-clips/{$clip->id}/reingest")
            ->assertStatus(422);
    }

    public function test_put_script_persists_the_trim_window(): void
    {
        $clip = DubClip::create([
            'source' => 'library',
            'title' => 'Trim',
            'status' => 'review',
            'source_video_path' => 'dub/clips/x/source.mp4',
        ]);

        $this->actingAs($this->admin())
            ->putJson("/api/admin/dub-clips/{$clip->id}/script", [
                'characters' => [['ref' => 'a', 'display_name' => 'X', 'color' => 'grape']],
                'lines' => [['character_ref' => 'a', 'start_ms' => 90000, 'end_ms' => 92000, 'text' => 'hi']],
                'clip_start_ms' => 88000,
                'clip_end_ms' => 95000,
            ])
            ->assertOk()
            ->assertJsonPath('clip_start_ms', 88000)
            ->assertJsonPath('clip_end_ms', 95000);

        $clip->refresh();
        $this->assertSame(88000, $clip->clip_start_ms);
        $this->assertSame(95000, $clip->clip_end_ms);
    }
}
