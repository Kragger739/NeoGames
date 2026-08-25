<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('game_rooms', function (Blueprint $table) {
            $table->string('artist_name')->nullable()->after('year_to');
        });
    }

    public function down(): void
    {
        Schema::table('game_rooms', function (Blueprint $table) {
            $table->dropColumn('artist_name');
        });
    }
};
