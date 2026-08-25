import type { GameMode } from "./roomTypes";

export const GAME_MODES: { value: GameMode; label: string; description: string }[] = [
  {
    value: "classic",
    label: "Classic",
    description: "First correct guess wins the round.",
  },
  {
    value: "battle_royale",
    label: "Battle Royale",
    description: "Guess wrong (or not at all) and you're out for the rest of the game.",
  },
  {
    value: "solo",
    label: "Solo",
    description: "Play at your own pace - no timer, replay as much as you want.",
  },
];
