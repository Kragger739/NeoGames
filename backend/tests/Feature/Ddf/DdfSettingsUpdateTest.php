<?php

namespace Tests\Feature\Ddf;

use App\Services\DdfGameService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class DdfSettingsUpdateTest extends TestCase
{
    use CreatesDdfRooms, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Event::fake();
    }

    public function test_settings_can_be_updated_in_the_lobby(): void
    {
        $room = $this->createDdfRoom(['state' => 'lobby']);

        app(DdfGameService::class)->updateSettings($room, ['rounds_per_voting' => 5]);

        $this->assertSame(5, $room->fresh()->ddfGame->rounds_per_voting);
    }

    public function test_settings_can_be_updated_mid_cycle_before_voting_starts(): void
    {
        $room = $this->createDdfRoom(['state' => 'question_result']);

        app(DdfGameService::class)->updateSettings($room, ['question_timer_seconds' => 45]);

        $this->assertSame(45, $room->fresh()->ddfGame->question_timer_seconds);
    }

    public function test_couch_mode_can_be_toggled_via_settings(): void
    {
        $room = $this->createDdfRoom(['state' => 'lobby', 'couch_mode' => true]);

        app(DdfGameService::class)->updateSettings($room, ['couch_mode' => false]);

        $this->assertFalse($room->fresh()->ddfGame->couch_mode);
    }

    public function test_settings_are_rejected_once_voting_has_started(): void
    {
        $room = $this->createDdfRoom(['state' => 'voting']);

        $this->expectException(ValidationException::class);
        app(DdfGameService::class)->updateSettings($room, ['rounds_per_voting' => 5]);
    }

    public function test_settings_are_rejected_once_voting_results_are_showing(): void
    {
        $room = $this->createDdfRoom(['state' => 'voting_results']);

        $this->expectException(ValidationException::class);
        app(DdfGameService::class)->updateSettings($room, ['rounds_per_voting' => 5]);
    }
}
