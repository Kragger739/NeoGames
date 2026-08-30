import { create } from "zustand";

import { api } from "../lib/api";

export interface SeedPlaylist {
  id: number;
  genre: string;
  spotify_playlist_id: string;
  label: string | null;
}

interface LastSync {
  at: string;
  summary: string;
  pool_size: number;
}

interface AdminPlaylistsState {
  genres: string[];
  playlists: SeedPlaylist[];
  poolSize: number;
  lastSync: LastSync | null;
  status: "idle" | "loading" | "ready";
  syncing: boolean;
  fetch: () => Promise<void>;
  add: (genre: string, playlist: string, label: string) => Promise<void>;
  remove: (id: number) => Promise<void>;
  sync: () => Promise<void>;
}

export const useAdminPlaylistsStore = create<AdminPlaylistsState>((set, get) => ({
  genres: [],
  playlists: [],
  poolSize: 0,
  lastSync: null,
  status: "idle",
  syncing: false,

  fetch: async () => {
    set({ status: "loading" });
    const { data } = await api.get("/api/admin/song-playlists");
    set({
      genres: data.genres,
      playlists: data.playlists,
      poolSize: data.pool_size,
      lastSync: data.last_sync ?? null,
      status: "ready",
    });
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
    set({ syncing: true });
    try {
      await api.post("/api/admin/song-playlists/sync");
    } finally {
      set({ syncing: false });
    }
  },
}));
