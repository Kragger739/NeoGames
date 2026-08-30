// The identity blob the backend sends for every user on every surface (self,
// players, friends, leaderboard). Mirrors User::avatarPayload() in the API.

export type CosmeticSlot = "frame" | "hat" | "accessory" | "badge" | "background" | "effect";

export type CosmeticRarity = "common" | "rare" | "epic";

export interface EquippedCosmetic {
  key: string;
  rarity: CosmeticRarity;
}

export interface AvatarData {
  avatar_url: string | null;
  level: number;
  cosmetics: Partial<Record<CosmeticSlot, EquippedCosmetic>>;
  is_admin?: boolean;
}

/** A safe empty payload for the brief window before `host` has loaded. */
export const EMPTY_AVATAR: AvatarData = { avatar_url: null, level: 1, cosmetics: {}, is_admin: false };
