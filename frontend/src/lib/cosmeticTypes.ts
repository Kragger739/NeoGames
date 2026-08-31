import type { AvatarData, CosmeticRarity, CosmeticSlot } from "./avatarData";

export type CosmeticSource = "starter" | "track" | "pass";

export interface CosmeticBrief {
  id: number;
  slot: CosmeticSlot;
  key: string;
  name: string;
  rarity: CosmeticRarity;
  image_url: string | null;
}

export interface CatalogCosmetic extends CosmeticBrief {
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
  free: CosmeticBrief | null;
  premium: CosmeticBrief | null;
  free_owned: boolean;
  premium_owned: boolean;
  has_pass: boolean;
}

export interface CosmeticsResponse {
  season: SeasonInfo | null;
  progress: { xp: number; current_tier: number; has_pass: boolean };
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
