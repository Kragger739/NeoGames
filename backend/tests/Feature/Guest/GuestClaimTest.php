<?php

namespace Tests\Feature\Guest;

use App\Models\GameRoom;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

class GuestClaimTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Notification::fake();
    }

    public function test_registering_from_a_guest_session_upgrades_the_same_row(): void
    {
        $guest = User::factory()->guest()->create();
        $room = GameRoom::factory()->create(['host_id' => $guest->id]);

        $response = $this->actingAs($guest)->postJson('/api/register', [
            'name' => 'Real Person',
            'email' => 'real@example.com',
            'password' => 'password123',
            'password_confirmation' => 'password123',
            'accepted_terms' => true,
        ])->assertCreated();

        $this->assertSame($guest->id, $response->json('id'));
        $this->assertSame(1, User::count());

        $guest->refresh();
        $this->assertFalse((bool) $guest->is_guest);
        $this->assertSame('real@example.com', $guest->email);
        $this->assertNotNull($guest->password);
        $this->assertNull($guest->email_verified_at);

        // The room hosted as a guest still points at the same account.
        $this->assertSame($guest->id, $room->fresh()->host_id);
    }

    public function test_registering_without_a_guest_session_creates_a_new_account(): void
    {
        $this->postJson('/api/register', [
            'name' => 'Fresh User',
            'email' => 'fresh@example.com',
            'password' => 'password123',
            'password_confirmation' => 'password123',
            'accepted_terms' => true,
        ])->assertCreated();

        $this->assertDatabaseHas('users', ['email' => 'fresh@example.com', 'is_guest' => false]);
    }
}
