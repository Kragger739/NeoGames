import { create } from "zustand";

import { api } from "../lib/api";
import type { CosmeticSlot } from "../lib/avatarData";
import type { CosmeticsResponse } from "../lib/cosmeticTypes";
import { useAuthStore } from "./authStore";

interface CosmeticsState {
  status: "idle" | "loading" | "ready";
  data: CosmeticsResponse | null;
  saving: boolean;
  fetch: () => Promise<void>;
  /** Sends the whole equipped map; nulls clear a slot. Refreshes the catalogue + host. */
  save: (equipped: Partial<Record<CosmeticSlot, number | null>>) => Promise<void>;
}

export const useCosmeticsStore = create<CosmeticsState>((set, get) => ({
  status: "idle",
  data: null,
  saving: false,

  fetch: async () => {
    set({ status: "loading" });
    try {
      const response = await api.get<CosmeticsResponse>("/api/cosmetics");
      set({ data: response.data, status: "ready" });
    } catch {
      set({ status: "ready" });
    }
  },

  save: async (equipped) => {
    set({ saving: true });
    try {
      await api.patch("/api/profile/cosmetics", { equipped });
      await Promise.all([get().fetch(), useAuthStore.getState().refreshHost()]);
    } finally {
      set({ saving: false });
    }
  },
}));
