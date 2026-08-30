<?php

namespace Tests\Feature\Workshop;

use App\Models\Dataset;
use App\Models\DdfQuestion;
use App\Models\GameRoom;
use App\Models\RoomPlayer;
use App\Models\User;
use App\Services\DdfGameService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class DdfDatasetGameTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake();
        Event::fake();
    }

    private function ddfDatasetWith(User $owner, string $language, int $questions, string $visibility = 'private'): Dataset
    {
        $dataset = Dataset::create([
            'owner_id' => $owner->id, 'name' => 'Set', 'type' => 'ddf',
            'visibility' => $visibility, 'language' => $language,
        ]);

        for ($i = 0; $i < $questions; $i++) {
            $dataset->questions()->create([
                'category' => 'history', 'language' => $language,
                'text' => "Custom Q {$i}", 'correct_answer' => 'a', 'position' => $i,
            ]);
        }

        return $dataset;
    }

    private function roomInGameStart(GameRoom $room): GameRoom
    {
        $room->ddfGame->update(['state' => 'game_start']);
        foreach (range(1, 2) as $_) {
            RoomPlayer::factory()->for($room, 'room')->create()->ddfState()->create(['hearts' => 3, 'is_camera_ready' => true]);
        }

        return $room->fresh();
    }

    public function test_creating_a_game_with_a_dataset_scopes_questions_to_it_and_ignores_language(): void
    {
        $host = User::factory()->create();
        // Built-in EN questions that must NOT be served.
        DdfQuestion::create(['category' => 'history', 'language' => 'en', 'text' => 'Built-in', 'correct_answer' => 'x']);
        $dataset = $this->ddfDatasetWith($host, 'de', 3);

        $response = $this->actingAs($host)->postJson('/api/ddf-rooms', [
            'language' => 'en',
            'dataset_id' => $dataset->id,
        ]);
        $response->assertCreated();
        $response->assertJsonPath('dataset_id', $dataset->id);
        $response->assertJsonPath('dataset_name', 'Set');
        // Dataset language wins over the submitted 'en'.
        $response->assertJsonPath('language', 'de');

        $room = $this->roomInGameStart(GameRoom::where('code', $response->json('code'))->firstOrFail());

        foreach (range(1, 4) as $_) {
            app(DdfGameService::class)->startNextQuestion($room->fresh());
            $served = $room->fresh()->ddfGame->currentQuestion;
            $this->assertSame($dataset->id, $served->dataset_id, 'served a non-dataset question');
        }
    }

    public function test_a_game_with_no_dataset_still_serves_only_built_in_questions(): void
    {
        $host = User::factory()->create();
        DdfQuestion::create(['category' => 'history', 'language' => 'en', 'text' => 'Built-in A', 'correct_answer' => 'x']);
        DdfQuestion::create(['category' => 'history', 'language' => 'en', 'text' => 'Built-in B', 'correct_answer' => 'x']);
        // A custom dataset question in the same language that must be excluded.
        $this->ddfDatasetWith($host, 'en', 2);

        $room = GameRoom::factory()->for($host, 'host')->create(['game' => 'ddf']);
        $room->ddfGame()->create(['state' => 'game_start', 'language' => 'en']);
        $room = $this->roomInGameStart($room->fresh());

        foreach (range(1, 3) as $_) {
            app(DdfGameService::class)->startNextQuestion($room->fresh());
            $this->assertNull($room->fresh()->ddfGame->currentQuestion->dataset_id);
        }
    }

    public function test_an_empty_dataset_is_rejected_at_creation(): void
    {
        $host = User::factory()->create();
        $empty = Dataset::create(['owner_id' => $host->id, 'name' => 'Empty', 'type' => 'ddf', 'visibility' => 'private', 'language' => 'en']);

        $this->actingAs($host)->postJson('/api/ddf-rooms', ['dataset_id' => $empty->id])
            ->assertUnprocessable()->assertJsonValidationErrors('dataset_id');
    }

    public function test_another_users_private_dataset_cannot_be_selected_but_a_public_one_can(): void
    {
        $host = User::factory()->create();
        $other = User::factory()->create();
        $private = $this->ddfDatasetWith($other, 'en', 2, 'private');
        $public = $this->ddfDatasetWith($other, 'en', 2, 'public');

        $this->actingAs($host)->postJson('/api/ddf-rooms', ['dataset_id' => $private->id])
            ->assertUnprocessable()->assertJsonValidationErrors('dataset_id');

        $this->actingAs($host)->postJson('/api/ddf-rooms', ['dataset_id' => $public->id])
            ->assertCreated()->assertJsonPath('dataset_id', $public->id);
    }

    public function test_deleting_the_dataset_mid_game_falls_back_to_built_in_questions(): void
    {
        $host = User::factory()->create();
        DdfQuestion::create(['category' => 'history', 'language' => 'en', 'text' => 'Built-in fallback', 'correct_answer' => 'x']);
        $dataset = $this->ddfDatasetWith($host, 'en', 2);

        $room = GameRoom::factory()->for($host, 'host')->create(['game' => 'ddf']);
        $room->ddfGame()->create(['state' => 'game_start', 'language' => 'en', 'dataset_id' => $dataset->id]);
        $room = $this->roomInGameStart($room->fresh());

        $dataset->delete(); // nullOnDelete -> ddf_games.dataset_id becomes NULL

        app(DdfGameService::class)->startNextQuestion($room->fresh());

        $this->assertNull($room->fresh()->ddfGame->dataset_id);
        $this->assertNull($room->fresh()->ddfGame->currentQuestion->dataset_id);
    }
}
