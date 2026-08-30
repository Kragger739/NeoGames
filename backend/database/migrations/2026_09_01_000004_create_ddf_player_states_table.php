<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ddf_player_states', function (Blueprint $table) {
            $table->id();
            $table->foreignId('room_player_id')->unique()->constrained('room_players')->cascadeOnDelete();
            $table->unsignedTinyInteger('hearts')->default(3);
            $table->boolean('is_eliminated')->default(false);
            $table->timestamp('eliminated_at')->nullable();
            $table->boolean('is_camera_ready')->default(false);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ddf_player_states');
    }
};
