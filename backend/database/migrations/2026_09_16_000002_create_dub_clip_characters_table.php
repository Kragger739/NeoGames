<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A speaking part in a dub clip. `key` is the raw diarization label
 * (e.g. SPEAKER_00); `display_name` is the human name set in the
 * review-and-fix editor. `color` is a Confetti-Pop token name the UI uses
 * to give each character its own hue.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('dub_clip_characters', function (Blueprint $table) {
            $table->id();
            $table->foreignId('dub_clip_id')->constrained('dub_clips')->cascadeOnDelete();
            $table->string('key');
            $table->string('display_name');
            $table->string('color')->nullable(); // grape | turquoise | coral | sunflower | bubblegum
            $table->unsignedTinyInteger('position')->default(0);
            $table->timestamps();

            $table->unique(['dub_clip_id', 'key']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('dub_clip_characters');
    }
};
