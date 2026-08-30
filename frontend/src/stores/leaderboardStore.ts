import { create } from "zustand";

import { api } from "../lib/api";
import type { LeaderboardResponse } from "../lib/cosmeticTypes";

interface LeaderboardState {
  status: "idle" | "loading" | "ready";
  data: LeaderboardResponse | null;
  fetch: () => Promise<void>;
}

export const useLeaderboardStore = create<LeaderboardState>((set) => ({
  status: "idle",
  data: null,

  fetch: async () => {
    set({ status: "loading" });
    try {
      const response = await api.get<LeaderboardResponse>("/api/leaderboard");
      set({ data: response.data, status: "ready" });
    } catch {
      set({ status: "ready" });
    }
  },
}));
