import type { AvatarData, CosmeticRarity, CosmeticSlot } from "./avatarData";

export type CosmeticSource = "starter" | "track" | "pass";

export interface CatalogCosmetic {
  id: number;
  slot: CosmeticSlot;
  key: string;
  name: string;
  rarity: CosmeticRarity;
  source: CosmeticSource;
  tier: number | null;
  owned: boolean;
}

export interface SeasonInfo {
  name: string;
  slug: string;
  starts_at: string;
  ends_at: string;
}

export interface TierInfo {
  tier: number;
  threshold: number;
  cosmetic: {
    id: number;
    slot: CosmeticSlot;
    key: string;
    name: string;
    rarity: CosmeticRarity;
  } | null;
  owned: boolean;
}

export interface CosmeticsResponse {
  season: SeasonInfo | null;
  progress: { xp: number; current_tier: number };
  equipped: Partial<Record<CosmeticSlot, number>>;
  catalog: CatalogCosmetic[];
  tiers: TierInfo[];
}

export interface LeaderboardEntry {
  rank: number;
  username: string;
  season_xp: number;
  avatar: AvatarData;
}

export interface LeaderboardResponse {
  season: { name: string; slug: string; ends_at: string } | null;
  entries: LeaderboardEntry[];
  me: { rank: number; season_xp: number } | null;
}
