<?php

namespace App\Console\Commands;

use App\Models\User;
use Illuminate\Console\Command;

/**
 * Deletes stale guest users (EnsureGuestOrUser rows). A guest is pruned once
 * it's older than --days AND none of its rooms have been touched inside that
 * window. FK cascades clear xp_events, daily_challenge_attempts,
 * season_progress, cosmetic_user, neo_coin_events, iconic_artist_user and
 * game_rooms (+ rounds + guesses); purgeArtifacts() handles the non-cascading
 * bits (avatar file, sessions row, notifications).
 */
class PruneGuestUsersCommand extends Command
{
    protected $signature = 'guests:prune {--days=7} {--dry-run}';

    protected $description = 'Delete stale guest users with no recent room activity.';

    public function handle(): int
    {
        $cutoff = now()->subDays((int) $this->option('days'));

        $query = User::query()
            ->where('is_guest', true)
            ->where('created_at', '<', $cutoff)
            ->whereDoesntHave('rooms', fn ($q) => $q->where('updated_at', '>=', $cutoff));

        if ($this->option('dry-run')) {
            $this->info("Would delete {$query->count()} stale guest users.");

            return self::SUCCESS;
        }

        $deleted = 0;

        $query->select('id', 'avatar_path')->chunkById(200, function ($users) use (&$deleted) {
            foreach ($users as $user) {
                $user->purgeArtifacts();
                $user->delete();
                $deleted++;
            }
        });

        $this->info("Deleted {$deleted} stale guest users.");

        return self::SUCCESS;
    }
}
