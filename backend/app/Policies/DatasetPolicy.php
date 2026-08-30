<?php

namespace App\Policies;

use App\Enums\DatasetVisibility;
use App\Models\Dataset;
use App\Models\User;

class DatasetPolicy
{
    /** Owners see their own; anyone sees a public dataset. */
    public function view(User $user, Dataset $dataset): bool
    {
        return $dataset->owner_id === $user->id
            || $dataset->visibility === DatasetVisibility::Public;
    }

    /** Only the owner edits (name, visibility, questions, tracks). */
    public function update(User $user, Dataset $dataset): bool
    {
        return $dataset->owner_id === $user->id;
    }

    /** Only the owner deletes. */
    public function delete(User $user, Dataset $dataset): bool
    {
        return $dataset->owner_id === $user->id;
    }
}
