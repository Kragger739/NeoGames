import type { GameMode } from "./roomTypes";

// Level requirements for modes (and genres, and hosting a game night) are
// configured server-side in the unlock_requirements table and fetched via
// useUnlockStore - no hard-coded mirror here anymore.
export const GAME_MODES: { value: GameMode; label: string; description: string }[] = [
  {
    value: "classic",
    label: "Classic",
    description: "First correct guess wins the round - always the same curated set of iconic, all-time favorites.",
  },
  {
    value: "battle_royale",
    label: "Battle Royale",
    description: "Guess wrong (or not at all) and you're out for the rest of the game.",
  },
  {
    value: "custom",
    label: "Custom",
    description: "Configure everything yourself - genre, difficulty tiers, timeout, songs per round.",
  },
];
