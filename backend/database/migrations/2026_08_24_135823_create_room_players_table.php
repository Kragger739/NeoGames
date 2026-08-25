<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('room_players', function (Blueprint $table) {
            $table->id();
            $table->foreignId('room_id')->constrained('game_rooms')->cascadeOnDelete();
            $table->string('nickname');
            $table->string('connection_token', 64)->unique();
            $table->unsignedInteger('score')->default(0);
            $table->timestamps();

            $table->unique(['room_id', 'nickname']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('room_players');
    }
};
