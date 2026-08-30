<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('ddf_questions', function (Blueprint $table) {
            $table->string('language')->default('en')->after('category');
            $table->index('language');
        });

        Schema::table('ddf_games', function (Blueprint $table) {
            $table->string('language')->default('en')->after('rounds_per_voting');
        });
    }

    public function down(): void
    {
        Schema::table('ddf_questions', function (Blueprint $table) {
            $table->dropColumn('language');
        });

        Schema::table('ddf_games', function (Blueprint $table) {
            $table->dropColumn('language');
        });
    }
};
