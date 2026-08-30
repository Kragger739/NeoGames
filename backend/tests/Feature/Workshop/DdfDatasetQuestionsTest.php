<?php

namespace Tests\Feature\Workshop;

use App\Models\Dataset;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DdfDatasetQuestionsTest extends TestCase
{
    use RefreshDatabase;

    private function ddfDataset(User $owner, string $visibility = 'private'): Dataset
    {
        return Dataset::create([
            'owner_id' => $owner->id, 'name' => 'Q', 'type' => 'ddf',
            'visibility' => $visibility, 'language' => 'de',
        ]);
    }

    public function test_add_edit_delete_a_question(): void
    {
        $owner = User::factory()->create();
        $dataset = $this->ddfDataset($owner);

        $add = $this->actingAs($owner)->postJson("/api/datasets/{$dataset->id}/questions", [
            'text' => 'What is the capital of France?',
            'correct_answer' => 'Paris',
            'category' => 'geography',
        ]);
        $add->assertCreated();
        $add->assertJsonCount(1, 'questions');
        // Inherits the dataset's language, not en.
        $this->assertDatabaseHas('ddf_questions', [
            'dataset_id' => $dataset->id, 'language' => 'de', 'text' => 'What is the capital of France?',
        ]);
        $questionId = $add->json('questions.0.id');

        $edit = $this->actingAs($owner)->patchJson("/api/datasets/{$dataset->id}/questions/{$questionId}", [
            'text' => 'Capital of France?',
            'correct_answer' => 'Paris',
            'category' => 'culture',
        ]);
        $edit->assertOk();
        $edit->assertJsonPath('questions.0.category', 'culture');

        $this->actingAs($owner)->deleteJson("/api/datasets/{$dataset->id}/questions/{$questionId}")
            ->assertOk()->assertJsonCount(0, 'questions');
    }

    public function test_a_question_cannot_be_saved_with_a_blank_field_or_bad_category(): void
    {
        $owner = User::factory()->create();
        $dataset = $this->ddfDataset($owner);

        $this->actingAs($owner)->postJson("/api/datasets/{$dataset->id}/questions", [
            'text' => '', 'correct_answer' => 'A', 'category' => 'geography',
        ])->assertUnprocessable()->assertJsonValidationErrors('text');

        $this->actingAs($owner)->postJson("/api/datasets/{$dataset->id}/questions", [
            'text' => 'Valid question text', 'correct_answer' => '', 'category' => 'geography',
        ])->assertUnprocessable()->assertJsonValidationErrors('correct_answer');

        $this->actingAs($owner)->postJson("/api/datasets/{$dataset->id}/questions", [
            'text' => 'Valid question text', 'correct_answer' => 'A', 'category' => 'made_up',
        ])->assertUnprocessable()->assertJsonValidationErrors('category');
    }

    public function test_reorder_sets_position_and_rejects_a_foreign_or_partial_list(): void
    {
        $owner = User::factory()->create();
        $dataset = $this->ddfDataset($owner);
        $a = $dataset->questions()->create(['category' => 'history', 'language' => 'de', 'text' => 'A?', 'correct_answer' => 'a', 'position' => 0]);
        $b = $dataset->questions()->create(['category' => 'history', 'language' => 'de', 'text' => 'B?', 'correct_answer' => 'b', 'position' => 1]);
        $c = $dataset->questions()->create(['category' => 'history', 'language' => 'de', 'text' => 'C?', 'correct_answer' => 'c', 'position' => 2]);

        $this->actingAs($owner)->patchJson("/api/datasets/{$dataset->id}/questions/reorder", [
            'ids' => [$c->id, $a->id, $b->id],
        ])->assertOk();

        $this->assertSame(0, $c->fresh()->position);
        $this->assertSame(1, $a->fresh()->position);
        $this->assertSame(2, $b->fresh()->position);

        $this->actingAs($owner)->patchJson("/api/datasets/{$dataset->id}/questions/reorder", [
            'ids' => [$a->id, $b->id],
        ])->assertUnprocessable()->assertJsonValidationErrors('ids');

        $this->actingAs($owner)->patchJson("/api/datasets/{$dataset->id}/questions/reorder", [
            'ids' => [$a->id, $b->id, $c->id, 99999],
        ])->assertUnprocessable()->assertJsonValidationErrors('ids');
    }

    public function test_a_non_owner_cannot_manage_questions_even_on_a_public_dataset(): void
    {
        $owner = User::factory()->create();
        $other = User::factory()->create();
        $dataset = $this->ddfDataset($owner, 'public');
        $question = $dataset->questions()->create(['category' => 'history', 'language' => 'de', 'text' => 'A?', 'correct_answer' => 'a', 'position' => 0]);

        $this->actingAs($other)->postJson("/api/datasets/{$dataset->id}/questions", [
            'text' => 'Sneaky question', 'correct_answer' => 'x', 'category' => 'history',
        ])->assertForbidden();

        $this->actingAs($other)->deleteJson("/api/datasets/{$dataset->id}/questions/{$question->id}")->assertForbidden();
    }

    public function test_questions_cannot_be_added_to_a_songle_dataset(): void
    {
        $owner = User::factory()->create();
        $songle = Dataset::create(['owner_id' => $owner->id, 'name' => 'S', 'type' => 'songle', 'visibility' => 'private']);

        $this->actingAs($owner)->postJson("/api/datasets/{$songle->id}/questions", [
            'text' => 'Valid question text', 'correct_answer' => 'A', 'category' => 'history',
        ])->assertStatus(422);
    }
}
