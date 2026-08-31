import { create } from "zustand";

import { api } from "../lib/api";

/**
 * The { gate-key: required player level } map from the backend, used to
 * render locked modes / genres and the game-night button. Keys are
 * "game_night", "mode:<value>", "genre:<value>"; an unknown key means no
 * lock (level 1).
 */
interface UnlockState {
  requirements: Record<string, number>;
  status: "idle" | "loading" | "ready";
  fetch: () => Promise<void>;
  requiredLevel: (key: string) => number;
  locked: (key: string, level: number | null | undefined) => boolean;
}

export const useUnlockStore = create<UnlockState>((set, get) => ({
  requirements: {},
  status: "idle",

  fetch: async () => {
    if (get().status !== "idle") return;
    set({ status: "loading" });
    try {
      const { data } = await api.get<Record<string, number>>("/api/unlock-requirements");
      set({ requirements: data ?? {}, status: "ready" });
    } catch {
      // Non-fatal: fall back to "everything unlocked" and let a later
      // screen retry.
      set({ status: "idle" });
    }
  },

  requiredLevel: (key) => get().requirements[key] ?? 1,
  locked: (key, level) => (level ?? 1) < (get().requirements[key] ?? 1),
}));
