import { create } from "zustand";

import { api } from "../lib/api";
import { firstValidationError } from "../lib/errors";

export interface SeedPlaylist {
  id: number;
  genre: string;
  spotify_playlist_id: string;
  label: string | null;
}

export interface SyncStatus {
  state: "queued" | "running" | "done" | "error";
  summary: string | null;
  started_at: string | null;
  finished_at: string | null;
  at: string;
  pool_size: number;
}

interface AdminPlaylistsState {
  genres: string[];
  playlists: SeedPlaylist[];
  poolSize: number;
  lastSync: SyncStatus | null;
  status: "idle" | "loading" | "ready";
  syncError: string | null;
  fetch: () => Promise<void>;
  add: (genre: string, playlist: string, label: string) => Promise<void>;
  remove: (id: number) => Promise<void>;
  sync: () => Promise<void>;
}

let pollTimer: ReturnType<typeof setTimeout> | null = null;

export const useAdminPlaylistsStore = create<AdminPlaylistsState>((set, get) => {
  function schedulePoll() {
    if (pollTimer) clearTimeout(pollTimer);
    pollTimer = setTimeout(() => {
      pollTimer = null;
      void get()
        .fetch()
        .then(() => {
          const s = get().lastSync?.state;
          if (s === "queued" || s === "running") schedulePoll();
        });
    }, 4000);
  }

  return {
    genres: [],
    playlists: [],
    poolSize: 0,
    lastSync: null,
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
        status: "ready",
      });
      // Resume polling if a sync is still in flight (e.g. after navigating
      // back to the page).
      const s = data.last_sync?.state;
      if ((s === "queued" || s === "running") && !pollTimer) schedulePoll();
    },

    add: async (genre, playlist, label) => {
      await api.post("/api/admin/song-playlists", { genre, playlist, label: label || null });
      await get().fetch();
    },

    remove: async (id) => {
      await api.delete(`/api/admin/song-playlists/${id}`);
      set({ playlists: get().playlists.filter((p) => p.id !== id) });
    },

    sync: async () => {
      set({ syncError: null });
      try {
        const { data } = await api.post("/api/admin/song-playlists/sync");
        if (data.last_sync) set({ lastSync: data.last_sync });
        schedulePoll();
      } catch (err) {
        set({ syncError: firstValidationError(err) });
      }
    },
  };
});
