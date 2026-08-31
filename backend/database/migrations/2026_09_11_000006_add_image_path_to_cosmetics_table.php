<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * An admin-uploaded cosmetic image (PNG/WebP on the 'public' disk). When set,
 * the client renders it as an <img> layer instead of looking `key` up in the
 * hand-authored SVG registry - so a new cosmetic no longer needs a frontend
 * code change. A null image_path keeps the existing key -> registry SVG path.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('cosmetics', function (Blueprint $table) {
            $table->string('image_path')->nullable()->after('rarity');
        });
    }

    public function down(): void
    {
        Schema::table('cosmetics', function (Blueprint $table) {
            $table->dropColumn('image_path');
        });
    }
};
