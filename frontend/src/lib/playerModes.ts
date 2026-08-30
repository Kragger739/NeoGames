import type { PlayerMode } from "./roomTypes";

export const PLAYER_MODES: { value: PlayerMode; label: string; description: string }[] = [
  {
    value: "multiplayer",
    label: "Multiplayer",
    description: "Invite friends or share a link - everyone plays together.",
  },
  {
    value: "solo",
    label: "Solo",
    description: "Play at your own pace - no timer, replay as much as you want. Just you.",
  },
];
