<?php

namespace Tests\Feature\Workshop;

use App\Models\Dataset;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DatasetCrudTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_user_can_create_a_ddf_dataset(): void
    {
        $user = User::factory()->create();

        $response = $this->actingAs($user)->postJson('/api/datasets', [
            'name' => 'Family Quiz',
            'type' => 'ddf',
            'language' => 'en',
        ]);

        $response->assertCreated();
        $response->assertJsonPath('name', 'Family Quiz');
        $response->assertJsonPath('type', 'ddf');
        $response->assertJsonPath('visibility', 'private');
        $response->assertJsonPath('language', 'en');
        $response->assertJsonPath('questions', []);
        $this->assertDatabaseHas('datasets', ['owner_id' => $user->id, 'name' => 'Family Quiz']);
    }

    public function test_a_user_can_create_a_songle_dataset_without_a_language(): void
    {
        $user = User::factory()->create();

        $response = $this->actingAs($user)->postJson('/api/datasets', [
            'name' => 'Party Mix',
            'type' => 'songle',
        ]);

        $response->assertCreated();
        $response->assertJsonPath('type', 'songle');
        $response->assertJsonPath('tracks', []);
    }

    public function test_a_ddf_dataset_requires_a_language(): void
    {
        $this->actingAs(User::factory()->create())
            ->postJson('/api/datasets', ['name' => 'X', 'type' => 'ddf'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('language');
    }

    public function test_index_splits_mine_from_community_and_filters_by_type(): void
    {
        $me = User::factory()->create();
        $other = User::factory()->create();

        $mineDdf = Dataset::create(['owner_id' => $me->id, 'name' => 'Mine DDF', 'type' => 'ddf', 'visibility' => 'private', 'language' => 'en']);
        Dataset::create(['owner_id' => $me->id, 'name' => 'Mine Songle', 'type' => 'songle', 'visibility' => 'private']);
        $publicOther = Dataset::create(['owner_id' => $other->id, 'name' => 'Public DDF', 'type' => 'ddf', 'visibility' => 'public', 'language' => 'de']);
        Dataset::create(['owner_id' => $other->id, 'name' => 'Private DDF', 'type' => 'ddf', 'visibility' => 'private', 'language' => 'en']);

        $response = $this->actingAs($me)->getJson('/api/datasets?type=ddf');

        $response->assertOk();
        $mineIds = collect($response->json('mine'))->pluck('id');
        $communityIds = collect($response->json('community'))->pluck('id');

        $this->assertEqualsCanonicalizing([$mineDdf->id], $mineIds->all());
        $this->assertEqualsCanonicalizing([$publicOther->id], $communityIds->all());
    }

    public function test_show_allows_owner_and_public_but_not_a_private_dataset_of_another_user(): void
    {
        $me = User::factory()->create();
        $other = User::factory()->create();

        $mine = Dataset::create(['owner_id' => $me->id, 'name' => 'Mine', 'type' => 'ddf', 'visibility' => 'private', 'language' => 'en']);
        $public = Dataset::create(['owner_id' => $other->id, 'name' => 'Pub', 'type' => 'ddf', 'visibility' => 'public', 'language' => 'en']);
        $private = Dataset::create(['owner_id' => $other->id, 'name' => 'Priv', 'type' => 'ddf', 'visibility' => 'private', 'language' => 'en']);

        $this->actingAs($me)->getJson("/api/datasets/{$mine->id}")->assertOk();
        $this->actingAs($me)->getJson("/api/datasets/{$public->id}")->assertOk();
        $this->actingAs($me)->getJson("/api/datasets/{$private->id}")->assertForbidden();
    }

    public function test_only_the_owner_can_update_or_delete(): void
    {
        $owner = User::factory()->create();
        $other = User::factory()->create();
        $dataset = Dataset::create(['owner_id' => $owner->id, 'name' => 'X', 'type' => 'ddf', 'visibility' => 'public', 'language' => 'en']);

        $this->actingAs($other)->patchJson("/api/datasets/{$dataset->id}", ['name' => 'Hacked'])->assertForbidden();
        $this->actingAs($other)->deleteJson("/api/datasets/{$dataset->id}")->assertForbidden();

        $this->actingAs($owner)->patchJson("/api/datasets/{$dataset->id}", ['name' => 'Renamed', 'visibility' => 'private'])->assertOk();
        $this->assertDatabaseHas('datasets', ['id' => $dataset->id, 'name' => 'Renamed', 'visibility' => 'private']);

        $this->actingAs($owner)->deleteJson("/api/datasets/{$dataset->id}")->assertNoContent();
        $this->assertDatabaseMissing('datasets', ['id' => $dataset->id]);
    }

    public function test_duplicate_deep_copies_into_a_new_private_dataset_owned_by_the_caller(): void
    {
        $owner = User::factory()->create();
        $other = User::factory()->create();
        $dataset = Dataset::create(['owner_id' => $owner->id, 'name' => 'Great Quiz', 'type' => 'ddf', 'visibility' => 'public', 'language' => 'en']);
        $dataset->questions()->create(['category' => 'history', 'language' => 'en', 'text' => 'When?', 'correct_answer' => '1989', 'position' => 0]);
        $dataset->questions()->create(['category' => 'science', 'language' => 'en', 'text' => 'What?', 'correct_answer' => 'Water', 'position' => 1]);

        $response = $this->actingAs($other)->postJson("/api/datasets/{$dataset->id}/duplicate");

        $response->assertCreated();
        $response->assertJsonPath('name', 'Great Quiz (copy)');
        $response->assertJsonPath('visibility', 'private');
        $response->assertJsonPath('owner_id', $other->id);
        $this->assertCount(2, $response->json('questions'));
    }

    public function test_dataset_endpoints_require_authentication(): void
    {
        $this->getJson('/api/datasets')->assertUnauthorized();
        $this->postJson('/api/datasets', [])->assertUnauthorized();
    }
}
