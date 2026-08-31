<?php

namespace App\Rules;

use App\Models\UnlockRequirement;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Gates a game-room field value (a mode or a genre) behind the player level
 * configured in the unlock_requirements table, keyed "{prefix}:{value}" -
 * e.g. "mode:battle_royale", "genre:pop". A no-op when the value is blank or
 * has no configured requirement (level 1), so it's safe to attach
 * unconditionally to the same rule array in StoreGameRoomRequest and
 * UpdateRoomSettingsRequest.
 */
class RequiresUnlockLevel implements ValidationRule
{
    public function __construct(
        private FormRequest $request,
        private string $prefix,
    ) {}

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (blank($value)) {
            return;
        }

        $required = UnlockRequirement::levelFor("{$this->prefix}:{$value}");
        $level = (int) ($this->request->user()?->level ?? 1);

        if ($level < $required) {
            $label = str((string) $value)->headline()->toString();
            $fail("{$label} unlocks at level {$required} (you're level {$level}).");
        }
    }
}
