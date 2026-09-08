<?php

use App\Models\UnlockRequirement;
use Illuminate\Database\Migrations\Migration;

/**
 * Adds the "iconic_series" gate key. Default level 1 = no lock; retune from
 * /admin/unlocks. Uses the model (not DB::table) so booted()'s saved() hook
 * busts the unlock_requirements:map cache, and updateOrCreate so a re-run is
 * a no-op.
 */
return new class extends Migration
{
    public function up(): void
    {
        UnlockRequirement::updateOrCreate(
            ['key' => 'iconic_series'],
            ['required_level' => 1],
        );
    }

    public function down(): void
    {
        UnlockRequirement::where('key', 'iconic_series')->delete();
    }
};
