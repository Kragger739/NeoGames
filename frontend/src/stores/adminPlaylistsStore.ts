import { create } from "zustand";

import { api } from "../lib/api";
import { firstValidationError } from "../lib/errors";

export interface SeedPlaylist {
  id: number;
  genre: string;
  spotify_playlist_id: string;
  label: string | null;
}

export interface SyncProgress {
  phase: "idle" | "prepare" | "seed" | "done" | "error";
  prepared_count: number;
  total_playlists: number;
  seeded: number;
  skipped: number;
  already: number;
  total_items: number;
  failed_playlists: string[];
  rate_limited_until: number | null;
  error: string | null;
  summary: string | null;
  pool_size: number;
}

interface LastSync {
  state: "queued" | "running" | "done" | "error";
  summary: string | null;
  at: string;
}

interface AdminPlaylistsState {
  genres: string[];
  playlists: SeedPlaylist[];
  poolSize: number;
  lastSync: LastSync | null;
  progress: SyncProgress | null;
  running: boolean;
  status: "idle" | "loading" | "ready";
  syncError: string | null;
  fetch: () => Promise<void>;
  add: (genre: string, playlist: string, label: string) => Promise<void>;
  remove: (id: number) => Promise<void>;
  startSync: (fresh: boolean) => Promise<void>;
  stopSync: () => void;
}

const sleep = (ms: number) => new Promise((r) => setTimeout(r, ms));

// A run is browser-driven: this flag stops the loop if the admin navigates
// away or clicks Stop. Module-scoped so it survives re-renders.
let abort = false;

export const useAdminPlaylistsStore = create<AdminPlaylistsState>((set, get) => {
  async function runLoop() {
    while (!abort) {
      let res: SyncProgress;
      try {
        res = (await api.post("/api/admin/song-playlists/sync")).data;
      } catch (err) {
        set({ syncError: firstValidationError(err), running: false });
        return;
      }
      set({ progress: res });
      if (res.phase === "done" || res.phase === "error" || res.phase === "idle") {
        set({ running: false });
        await get().fetch();
        return;
      }
      if (res.rate_limited_until) {
        // Spotify / iTunes rate limit - wait out the cooldown, then resume.
        const waitMs = Math.max(1000, res.rate_limited_until * 1000 - Date.now() + 500);
        await sleep(waitMs);
      } else {
        await sleep(res.phase === "prepare" ? 400 : 900);
      }
    }
    set({ running: false });
  }

  return {
    genres: [],
    playlists: [],
    poolSize: 0,
    lastSync: null,
    progress: null,
    running: false,
    status: "idle",
    syncError: null,

    fetch: async () => {
      if (get().status === "idle") set({ status: "loading" });
      const { data } = await api.get("/api/admin/song-playlists");
      set({
        genres: data.genres,
        playlists: data.playlists,
        poolSize: data.pool_size,
        lastSync: data.last_sync ?? null,
        progress: data.sync_progress ?? get().progress,
        status: "ready",
      });
      // A sync was left mid-run (e.g. page reload) - pick the loop back up.
      const p = data.sync_progress?.phase;
      if ((p === "prepare" || p === "seed") && !get().running) {
        abort = false;
        set({ running: true, syncError: null });
        void runLoop();
      }
    },

    add: async (genre, playlist, label) => {
      await api.post("/api/admin/song-playlists", { genre, playlist, label: label || null });
      await get().fetch();
    },

    remove: async (id) => {
      await api.delete(`/api/admin/song-playlists/${id}`);
      set({ playlists: get().playlists.filter((p) => p.id !== id) });
    },

    startSync: async (fresh) => {
      set({ syncError: null, progress: null });
      abort = false;
      try {
        const { data } = await api.post("/api/admin/song-playlists/sync", { start: true, fresh });
        set({ progress: data });
        if (data.phase === "error") return;
        set({ running: true });
        void runLoop();
      } catch (err) {
        set({ syncError: firstValidationError(err) });
      }
    },

    stopSync: () => {
      abort = true;
      set({ running: false });
    },
  };
});
