<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A dub clip can belong to a Workshop pack (datasets.type = 'dub'). Mirrors
 * ddf_questions.dataset_id: NULL = the admin library or a room upload;
 * non-null = one pack, cascade-deleted with it. `position` is the pack's
 * play order.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('dub_clips', function (Blueprint $table) {
            $table->foreignId('dataset_id')->nullable()->after('game_room_id')->constrained('datasets')->cascadeOnDelete();
            $table->unsignedInteger('position')->default(0)->after('is_public');
            $table->index(['dataset_id', 'position']);
        });
    }

    public function down(): void
    {
        Schema::table('dub_clips', function (Blueprint $table) {
            $table->dropConstrainedForeignId('dataset_id');
            $table->dropColumn('position');
        });
    }
};
