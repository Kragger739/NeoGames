<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A short video clip players dub over in "Dub Together". Two sources: the
 * admin-curated library (source=library, is_public) and one-off host
 * uploads made from a room's lobby (source=room_upload, game_room_id set).
 * The speech-detection pipeline fills music_bed_path + the characters/lines
 * child rows; a clip is only playable once status=ready.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('dub_clips', function (Blueprint $table) {
            $table->id();
            $table->string('source')->default('library'); // library | room_upload
            $table->foreignId('created_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('game_room_id')->nullable()->constrained('game_rooms')->cascadeOnDelete();
            $table->string('title');
            $table->string('status')->default('pending'); // pending | processing | review | ready | failed
            $table->boolean('is_public')->default(false);
            $table->string('source_video_path')->nullable();
            $table->string('music_bed_path')->nullable();
            $table->unsignedInteger('duration_ms')->nullable();
            $table->unsignedSmallInteger('video_width')->nullable();
            $table->unsignedSmallInteger('video_height')->nullable();
            $table->unsignedSmallInteger('video_fps')->nullable();
            $table->string('processing_job_id')->nullable();
            $table->text('processing_error')->nullable();
            $table->timestamp('processed_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('dub_clips');
    }
};
