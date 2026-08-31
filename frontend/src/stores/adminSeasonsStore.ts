import { create } from "zustand";

import { api } from "../lib/api";
import { firstValidationError } from "../lib/errors";

export interface SeasonTierRow {
  tier: number;
  xp_threshold: number;
  free_cosmetic_id: number | null;
  premium_cosmetic_id: number | null;
}

export interface SeasonRow {
  id: number;
  name: string;
  slug: string;
  starts_at: string;
  ends_at: string;
  is_current: boolean;
  tier_count: number;
  player_count: number;
  tiers: SeasonTierRow[];
}

export interface CosmeticLibItem {
  id: number;
  slot: string;
  key: string;
  name: string;
  rarity: string;
  source: string;
  season_id: number | null;
  image_url: string | null;
  has_registry_svg: boolean;
}

interface CreateSeasonPayload {
  name: string;
  starts_at?: string;
  length_days?: number;
  ends_at?: string;
  clone_from?: number | null;
}

interface AdminSeasonsState {
  seasons: SeasonRow[];
  cosmetics: CosmeticLibItem[];
  slots: string[];
  rarities: string[];
  sources: string[];
  status: "idle" | "loading" | "ready";
  error: string | null;
  fetch: () => Promise<void>;
  createSeason: (payload: CreateSeasonPayload) => Promise<void>;
  updateSeason: (id: number, payload: { name: string; starts_at: string; ends_at: string }) => Promise<void>;
  deleteSeason: (id: number) => Promise<void>;
  saveTiers: (seasonId: number, tiers: Omit<SeasonTierRow, "tier">[]) => Promise<void>;
  createCosmetic: (form: FormData) => Promise<void>;
  updateCosmetic: (id: number, form: FormData) => Promise<void>;
  deleteCosmetic: (id: number) => Promise<void>;
}

export const useAdminSeasonsStore = create<AdminSeasonsState>((set, get) => {
  async function reload() {
    const [seasons, cosmetics] = await Promise.all([
      api.get<{ seasons: SeasonRow[]; slots: string[] }>("/api/admin/seasons"),
      api.get<{ cosmetics: CosmeticLibItem[]; slots: string[]; rarities: string[]; sources: string[] }>(
        "/api/admin/cosmetics",
      ),
    ]);
    set({
      seasons: seasons.data.seasons,
      cosmetics: cosmetics.data.cosmetics,
      slots: cosmetics.data.slots,
      rarities: cosmetics.data.rarities,
      sources: cosmetics.data.sources,
      status: "ready",
    });
  }

  async function run(fn: () => Promise<unknown>) {
    set({ error: null });
    try {
      await fn();
      await reload();
    } catch (err) {
      set({ error: firstValidationError(err) });
      throw err;
    }
  }

  return {
    seasons: [],
    cosmetics: [],
    slots: [],
    rarities: [],
    sources: [],
    status: "idle",
    error: null,

    fetch: async () => {
      set({ status: get().status === "idle" ? "loading" : get().status, error: null });
      try {
        await reload();
      } catch (err) {
        set({ error: firstValidationError(err), status: "ready" });
      }
    },

    createSeason: (payload) => run(() => api.post("/api/admin/seasons", payload)),
    updateSeason: (id, payload) => run(() => api.patch(`/api/admin/seasons/${id}`, payload)),
    deleteSeason: (id) => run(() => api.delete(`/api/admin/seasons/${id}`)),
    saveTiers: (seasonId, tiers) =>
      run(() =>
        api.put(`/api/admin/seasons/${seasonId}/tiers`, {
          tiers: tiers.map((t) => ({
            xp_threshold: t.xp_threshold,
            free_cosmetic_id: t.free_cosmetic_id,
            premium_cosmetic_id: t.premium_cosmetic_id,
          })),
        }),
      ),
    createCosmetic: (form) => run(() => api.post("/api/admin/cosmetics", form)),
    updateCosmetic: (id, form) => run(() => api.post(`/api/admin/cosmetics/${id}`, form)),
    deleteCosmetic: (id) => run(() => api.delete(`/api/admin/cosmetics/${id}`)),
  };
});
