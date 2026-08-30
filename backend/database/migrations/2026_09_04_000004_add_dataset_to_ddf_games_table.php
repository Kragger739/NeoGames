<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('ddf_games', function (Blueprint $table) {
            // The custom question set this game draws from, or NULL for the
            // built-in pool. nullOnDelete: if the dataset is deleted mid-game
            // the game degrades to built-in questions rather than erroring.
            $table->foreignId('dataset_id')->nullable()->constrained('datasets')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('ddf_games', function (Blueprint $table) {
            $table->dropConstrainedForeignId('dataset_id');
        });
    }
};
