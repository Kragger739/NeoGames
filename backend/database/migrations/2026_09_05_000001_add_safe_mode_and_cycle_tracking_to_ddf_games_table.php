<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('ddf_games', function (Blueprint $table) {
            // When on, a player who was asked at least one question this
            // voting cycle and got all of them right can't be voted out.
            $table->boolean('safe_mode')->default(false);

            // current_question_number of the first question of the current
            // voting cycle. "This cycle's answers" = ddf_answers rows with
            // question_number >= this. Reset each time a cycle's first
            // question starts (see DdfGameService::startNextQuestion()).
            $table->unsignedInteger('cycle_started_question_number')->default(0);

            // Voting now auto-starts once the cycle's question count is hit,
            // so a shorter default cycle is the sensible out-of-the-box feel.
            $table->unsignedTinyInteger('rounds_per_voting')->default(2)->change();
        });
    }

    public function down(): void
    {
        Schema::table('ddf_games', function (Blueprint $table) {
            $table->unsignedTinyInteger('rounds_per_voting')->default(3)->change();
            $table->dropColumn(['safe_mode', 'cycle_started_question_number']);
        });
    }
};
