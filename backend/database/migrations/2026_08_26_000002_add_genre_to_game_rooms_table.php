<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('game_rooms', function (Blueprint $table) {
            $table->string('genre')->default('normal')->after('mode');
            $table->unsignedSmallInteger('year_from')->nullable()->after('genre');
            $table->unsignedSmallInteger('year_to')->nullable()->after('year_from');
        });
    }

    public function down(): void
    {
        Schema::table('game_rooms', function (Blueprint $table) {
            $table->dropColumn(['genre', 'year_from', 'year_to']);
        });
    }
};
