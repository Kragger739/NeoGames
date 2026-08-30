<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('ddf_questions', function (Blueprint $table) {
            // NULL = a built-in question (the default pool). A non-null value
            // scopes the row to one Workshop dataset - DdfGameService only
            // serves rows matching the game's dataset (or whereNull for the
            // default pool). cascadeOnDelete: deleting a dataset removes its
            // questions (and, via ddf_answers' own FK, their answer rows).
            $table->foreignId('dataset_id')->nullable()->after('language')->constrained('datasets')->cascadeOnDelete();
            // Editor display order only - the game still picks inRandomOrder().
            $table->unsignedInteger('position')->default(0)->after('correct_answer');
        });
    }

    public function down(): void
    {
        Schema::table('ddf_questions', function (Blueprint $table) {
            $table->dropConstrainedForeignId('dataset_id');
            $table->dropColumn('position');
        });
    }
};
