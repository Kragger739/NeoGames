<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * "Dub Together" clips can now be ingested from a video link (yt-dlp
 * downloads the whole video; the person trims it in the editor). A
 * non-null source_url marks a link-sourced clip; clip_start_ms /
 * clip_end_ms are the optional post-download trim window.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('dub_clips', function (Blueprint $table) {
            $table->string('source_url')->nullable()->after('source_video_path');
            $table->unsignedInteger('clip_start_ms')->nullable()->after('duration_ms');
            $table->unsignedInteger('clip_end_ms')->nullable()->after('clip_start_ms');
        });
    }

    public function down(): void
    {
        Schema::table('dub_clips', function (Blueprint $table) {
            $table->dropColumn(['source_url', 'clip_start_ms', 'clip_end_ms']);
        });
    }
};
