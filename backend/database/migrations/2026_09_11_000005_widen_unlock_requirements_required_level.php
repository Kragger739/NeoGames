<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Widen unlock_requirements.required_level from tinyint (max 255) so an
 * admin can set an effectively-unreachable cap (up to 999) to hard-lock a
 * mode or genre.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('unlock_requirements', function (Blueprint $table) {
            $table->unsignedSmallInteger('required_level')->default(1)->change();
        });
    }

    public function down(): void
    {
        Schema::table('unlock_requirements', function (Blueprint $table) {
            $table->unsignedTinyInteger('required_level')->default(1)->change();
        });
    }
};
