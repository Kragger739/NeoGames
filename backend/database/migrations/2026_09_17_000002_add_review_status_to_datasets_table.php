<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * "Dub Together" workshop packs contain user-uploaded video/audio, so they
 * need an admin approval step before going public. `review_status` defaults
 * to 'approved' so existing DDF/Songle datasets are unaffected - only dub
 * packs start as 'draft' (see DatasetController::store).
 * draft | pending | approved | rejected
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('datasets', function (Blueprint $table) {
            $table->string('review_status')->default('approved')->after('visibility');
            $table->text('review_note')->nullable()->after('review_status');
        });
    }

    public function down(): void
    {
        Schema::table('datasets', function (Blueprint $table) {
            $table->dropColumn(['review_status', 'review_note']);
        });
    }
};
