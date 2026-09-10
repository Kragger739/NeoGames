<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * One spoken line in a dub clip, in script order (`position`). Recording
 * walks these one at a time; the assigned player records over the
 * [start_ms, end_ms] window of the source video.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('dub_clip_lines', function (Blueprint $table) {
            $table->id();
            $table->foreignId('dub_clip_id')->constrained('dub_clips')->cascadeOnDelete();
            $table->foreignId('dub_clip_character_id')->constrained('dub_clip_characters')->cascadeOnDelete();
            $table->unsignedSmallInteger('position');
            $table->unsignedInteger('start_ms');
            $table->unsignedInteger('end_ms');
            $table->text('text')->nullable();
            $table->timestamps();

            $table->index(['dub_clip_id', 'position']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('dub_clip_lines');
    }
};
