<?php

namespace Tests\Feature\Workshop;

use App\Models\Dataset;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DubPackApprovalTest extends TestCase
{
    use RefreshDatabase;

    private function admin(): User
    {
        return tap(User::factory()->create(), fn ($u) => $u->forceFill(['is_admin' => true])->save());
    }

    private function pack(User $owner, array $attrs = []): Dataset
    {
        return $owner->datasets()->create(array_merge([
            'name' => 'Pack', 'type' => 'dub', 'visibility' => 'private', 'review_status' => 'draft',
        ], $attrs));
    }

    public function test_publishing_a_dub_pack_moves_it_to_pending_not_public_view(): void
    {
        $owner = User::factory()->create();
        $pack = $this->pack($owner);

        $this->actingAs($owner)
            ->patchJson("/api/datasets/{$pack->id}", ['visibility' => 'public'])
            ->assertOk()
            ->assertJsonPath('visibility', 'public')
            ->assertJsonPath('review_status', 'pending');

        // Not in another user's community list yet.
        $others = $this->actingAs(User::factory()->create())
            ->getJson('/api/datasets?type=dub')->json('community');
        $this->assertSame([], $others);
    }

    public function test_an_admin_approves_a_pending_pack_and_it_becomes_playable_community_content(): void
    {
        $owner = User::factory()->create();
        $pack = $this->pack($owner, ['visibility' => 'public', 'review_status' => 'pending']);

        $this->actingAs(User::factory()->create())
            ->postJson("/api/admin/dub-packs/{$pack->id}/approve")
            ->assertForbidden();

        $this->actingAs($this->admin())
            ->postJson("/api/admin/dub-packs/{$pack->id}/approve")
            ->assertOk()
            ->assertJsonPath('review_status', 'approved');

        $community = $this->actingAs(User::factory()->create())
            ->getJson('/api/datasets?type=dub')->json('community');
        $this->assertCount(1, $community);
        $this->assertSame($pack->id, $community[0]['id']);
    }

    public function test_rejecting_keeps_a_pack_out_and_records_the_note_for_the_owner(): void
    {
        $owner = User::factory()->create();
        $pack = $this->pack($owner, ['visibility' => 'public', 'review_status' => 'pending']);

        $this->actingAs($this->admin())
            ->postJson("/api/admin/dub-packs/{$pack->id}/reject", ['note' => 'Copyright concern'])
            ->assertOk()
            ->assertJsonPath('review_status', 'rejected');

        $this->assertSame('Copyright concern', $pack->fresh()->review_note);

        $detail = $this->actingAs($owner)->getJson("/api/datasets/{$pack->id}")->json();
        $this->assertSame('rejected', $detail['review_status']);
        $this->assertSame('Copyright concern', $detail['review_note']);

        // Re-publishing bumps it back to pending.
        $this->actingAs($owner)->patchJson("/api/datasets/{$pack->id}", ['visibility' => 'public'])
            ->assertJsonPath('review_status', 'pending');
    }

    public function test_a_users_own_pending_pack_still_shows_in_their_own_list(): void
    {
        $owner = User::factory()->create();
        $pack = $this->pack($owner, ['visibility' => 'public', 'review_status' => 'pending']);

        $mine = $this->actingAs($owner)->getJson('/api/datasets?type=dub')->json('mine');
        $this->assertCount(1, $mine);
        $this->assertSame('pending', $mine[0]['review_status']);
    }
}
