import type { GameMode, PlayerMode } from "./roomTypes";

export interface IconicArtist {
  id: number;
  name: string;
  image_url: string | null;
  /** This week's rotating freebie — playable without owning it, this week only. */
  free_this_week: boolean;
}

/**
 * The three modes offered in the trimmed Iconic Artist lobby. Each maps to a
 * (GameMode, RoomPlayerMode) pair the backend already understands.
 */
export const ICONIC_MODES: {
  key: string;
  label: string;
  description: string;
  mode: GameMode;
  player_mode: PlayerMode;
  /** Unlock key gating this option, or null when it's always available. */
  unlockKey: string | null;
}[] = [
  {
    key: "singleplayer",
    label: "Singleplayer",
    description: "Just you. No timer — the clip extends when you guess wrong.",
    mode: "custom",
    player_mode: "solo",
    unlockKey: null,
  },
  {
    key: "party",
    label: "Party",
    description: "Invite friends. First correct guess wins the round, nobody is out.",
    mode: "custom",
    player_mode: "multiplayer",
    unlockKey: null,
  },
  {
    key: "battle_royale",
    label: "Battle Royale",
    description: "Invite friends. Guess wrong and you're eliminated for the game.",
    mode: "battle_royale",
    player_mode: "multiplayer",
    unlockKey: "mode:battle_royale",
  },
];

export function iconicModeKey(mode: GameMode, playerMode: PlayerMode): string {
  if (mode === "battle_royale") return "battle_royale";
  return playerMode === "solo" ? "singleplayer" : "party";
}

export const ICONIC_ROUND_MIN = 3;
export const ICONIC_ROUND_MAX = 20;
export const ICONIC_ROUND_DEFAULT = 5;
export const ICONIC_EXTEND_MIN = 3;
export const ICONIC_EXTEND_MAX = 60;
