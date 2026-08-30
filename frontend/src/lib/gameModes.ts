import type { GameMode } from "./roomTypes";

// Mirrors backend/config/leveling.php's battle_royale_min_level - kept in
// sync manually, same already-accepted trade-off as leveling.ts's XP-curve
// mirror.
export const BATTLE_ROYALE_MIN_LEVEL = 3;

export const GAME_MODES: { value: GameMode; label: string; description: string; minLevel?: number }[] = [
  {
    value: "classic",
    label: "Classic",
    description: "First correct guess wins the round - always the same curated set of iconic, all-time favorites.",
  },
  {
    value: "battle_royale",
    label: "Battle Royale",
    description: "Guess wrong (or not at all) and you're out for the rest of the game.",
    minLevel: BATTLE_ROYALE_MIN_LEVEL,
  },
  {
    value: "custom",
    label: "Custom",
    description: "Configure everything yourself - genre, difficulty tiers, timeout, songs per round.",
  },
];
