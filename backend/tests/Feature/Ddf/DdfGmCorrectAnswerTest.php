<?php

namespace Tests\Feature\Ddf;

use App\Events\Ddf\DdfCorrectAnswerToGm;
use App\Events\Ddf\DdfQuestionStarted;
use App\Jobs\AdvanceDdfGameState;
use App\Models\User;
use App\Services\DdfGameService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class DdfGmCorrectAnswerTest extends TestCase
{
    use CreatesDdfRooms, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Event::fake();
        Queue::fake([AdvanceDdfGameState::class]);
    }

    public function test_starting_a_question_broadcasts_the_correct_answer_only_on_the_gm_channel(): void
    {
        $room = $this->createDdfRoom(['state' => 'game_start']);
        $this->addActivePlayer($room);
        $this->addActivePlayer($room);
        $this->seedQuestions();

        app(DdfGameService::class)->startNextQuestion($room->fresh());

        Event::assertDispatched(DdfCorrectAnswerToGm::class, function (DdfCorrectAnswerToGm $e) {
            return $e->game->currentQuestion->correct_answer === 'answer'
                && $e->broadcastOn()[0]->name === "private-room.{$e->game->room->code}.gm";
        });

        Event::assertDispatched(DdfQuestionStarted::class, function (DdfQuestionStarted $e) {
            return ! array_key_exists('correct_answer', $e->broadcastWith());
        });
    }

    public function test_gm_state_endpoint_returns_the_answer_key_to_the_host(): void
    {
        $room = $this->createDdfRoom(['state' => 'game_start']);
        $this->addActivePlayer($room);
        $this->addActivePlayer($room);
        $this->seedQuestions();
        app(DdfGameService::class)->startNextQuestion($room->fresh());

        $response = $this->actingAs($room->host)->getJson("/api/ddf-rooms/{$room->code}/gm-state");

        $response->assertOk();
        $response->assertJsonPath('correct_answer', 'answer');
        $response->assertJsonStructure(['correct_answer', 'cycle_answers', 'gm_answers', 'server_time']);
    }

    public function test_gm_state_endpoint_rejects_a_non_owning_host(): void
    {
        $room = $this->createDdfRoom(['state' => 'game_start']);
        $otherHost = User::factory()->create();

        $this->actingAs($otherHost)
            ->getJson("/api/ddf-rooms/{$room->code}/gm-state")
            ->assertForbidden();
    }

    public function test_gm_state_endpoint_rejects_a_player_token_or_no_auth(): void
    {
        $room = $this->createDdfRoom(['state' => 'game_start']);
        $player = $this->addActivePlayer($room);

        // auth:sanctum rejects both before the controller's host check runs.
        $this->withHeaders(['X-Player-Token' => $player->connection_token])
            ->getJson("/api/ddf-rooms/{$room->code}/gm-state")
            ->assertUnauthorized();

        $this->getJson("/api/ddf-rooms/{$room->code}/gm-state")->assertUnauthorized();
    }
}
