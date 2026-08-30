<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            // Relative path on the 'public' disk (e.g. "avatars/{uuid}.jpg"),
            // not a URL - User::avatar_url() builds the actual public URL
            // from this, same "store the fact, derive the presentation"
            // split as GameRoom's current_tier/current_song_index feeding
            // GameRoom::roundNumber().
            $table->string('avatar_path')->nullable()->after('username');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('avatar_path');
        });
    }
};
