import { create } from "zustand";

import { api } from "../lib/api";
import { firstValidationError } from "../lib/errors";

export interface UnlockRow {
  key: string;
  label: string;
  category: "game_night" | "mode" | "genre";
  required_level: number;
}

export interface DailySong {
  id: number;
  title: string;
  artist: string;
  album_art_url: string | null;
}

export interface DailyState {
  date: string;
  curated: boolean;
  has_attempts: boolean;
  songs: DailySong[];
}

interface AdminUnlocksState {
  requirements: UnlockRow[];
  daily: DailyState | null;
  status: "idle" | "loading" | "ready";
  error: string | null;
  fetch: () => Promise<void>;
  setLevel: (key: string, level: number) => Promise<void>;
  saveDaily: (songIds: number[]) => Promise<void>;
  searchSongs: (q: string) => Promise<DailySong[]>;
}

export const useAdminUnlocksStore = create<AdminUnlocksState>((set, get) => ({
  requirements: [],
  daily: null,
  status: "idle",
  error: null,

  fetch: async () => {
    set({ status: get().status === "idle" ? "loading" : get().status, error: null });
    try {
      const [reqs, daily] = await Promise.all([
        api.get<{ requirements: UnlockRow[] }>("/api/admin/unlock-requirements"),
        api.get<DailyState>("/api/admin/daily"),
      ]);
      set({ requirements: reqs.data.requirements, daily: daily.data, status: "ready" });
    } catch (err) {
      set({ error: firstValidationError(err), status: "ready" });
    }
  },

  setLevel: async (key, level) => {
    // Optimistic - snap the row, then persist.
    set({
      requirements: get().requirements.map((r) => (r.key === key ? { ...r, required_level: level } : r)),
      error: null,
    });
    try {
      await api.patch(`/api/admin/unlock-requirements/${key}`, { required_level: level });
    } catch (err) {
      set({ error: firstValidationError(err) });
      await get().fetch();
    }
  },

  saveDaily: async (songIds) => {
    const daily = get().daily;
    if (!daily) return;
    set({ error: null });
    try {
      const { data } = await api.patch<DailyState>(`/api/admin/daily/${daily.date}`, { song_ids: songIds });
      set({ daily: data });
    } catch (err) {
      set({ error: firstValidationError(err) });
    }
  },

  searchSongs: async (q) => {
    if (q.trim().length < 2) return [];
    try {
      const { data } = await api.get<{ results: DailySong[] }>("/api/admin/daily-songs/search", {
        params: { q },
      });
      return data.results;
    } catch {
      return [];
    }
  },
}));
