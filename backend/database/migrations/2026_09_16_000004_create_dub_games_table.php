<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Per-room state for "Dub Together" - mirrors ddf_games. The state machine
 * runs Lobby -> RoleClaim -> Recording -> Assembling -> Watch -> Rating ->
 * RoundComplete -> Finished, DubGameService is the sole writer, and
 * state_version guards a stale AdvanceDubState timer fire the same way DDF
 * does. Assembling is job-completion-bound, not timer-bound.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('dub_games', function (Blueprint $table) {
            $table->id();
            $table->foreignId('game_room_id')->unique()->constrained('game_rooms')->cascadeOnDelete();
            $table->string('state')->default('lobby');
            $table->unsignedInteger('state_version')->default(1);
            $table->timestamp('stage_started_at')->nullable();

            $table->foreignId('dub_clip_id')->nullable()->constrained('dub_clips')->nullOnDelete();
            $table->unsignedInteger('round_number')->default(0);
            $table->unsignedInteger('current_line_index')->default(0);
            $table->unsignedSmallInteger('line_timer_seconds')->default(0); // 0 = untimed
            $table->unsignedSmallInteger('watch_timer_seconds')->default(0);

            $table->string('assembled_video_path')->nullable();
            $table->text('assembly_error')->nullable();
            $table->timestamp('assembly_started_at')->nullable();

            $table->decimal('last_round_score', 3, 2)->nullable();
            $table->decimal('total_score', 6, 2)->default(0);

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('dub_games');
    }
};
