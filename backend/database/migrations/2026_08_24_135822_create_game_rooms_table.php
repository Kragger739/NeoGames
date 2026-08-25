<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('game_rooms', function (Blueprint $table) {
            $table->id();
            $table->string('code', 8)->unique();
            $table->foreignId('host_id')->constrained('users')->cascadeOnDelete();
            $table->string('status')->default('lobby');
            $table->string('game')->default('guess_the_song');
            $table->unsignedTinyInteger('songs_per_tier')->default(3);
            $table->unsignedSmallInteger('guess_timeout_seconds')->default(8);
            $table->string('current_tier')->nullable();
            $table->unsignedInteger('current_song_index')->default(0);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('game_rooms');
    }
};
