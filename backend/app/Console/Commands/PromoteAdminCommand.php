<?php

namespace App\Console\Commands;

use App\Models\User;
use Illuminate\Console\Command;

/**
 * Grants admin access from the CLI. The `promote_initial_admin` migration
 * is a no-op on a database that had no users when it ran (a fresh
 * environment), and the spec rules out a create-user UI - so this is the
 * supported way to bootstrap the first admin.
 */
class PromoteAdminCommand extends Command
{
    protected $signature = 'admin:promote {email : The email address of the user to promote}';

    protected $description = 'Grant admin access to the user with the given email address';

    public function handle(): int
    {
        $email = (string) $this->argument('email');

        $user = User::where('email', $email)->first();

        if ($user === null) {
            $this->error("No user with that email: {$email}");

            return self::FAILURE;
        }

        // is_admin is deliberately not fillable - force it on.
        $user->forceFill(['is_admin' => true])->save();

        $this->info("{$user->email} is now an admin.");

        return self::SUCCESS;
    }
}
