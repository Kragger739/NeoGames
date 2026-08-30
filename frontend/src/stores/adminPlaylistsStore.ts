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
  /** Set when polling gives up, e.g. the queue worker never picked the job up. */
  pollNote: string | null;
  fetch: () => Promise<void>;
  add: (genre: string, playlist: string, label: string) => Promise<void>;
  remove: (id: number) => Promise<void>;
  sync: () => Promise<void>;
  stopPolling: () => void;
}

const POLL_MS = 6000;
// Give up watching after this long overall (a big pool can genuinely take a
// while, but we shouldn't poll forever).
const MAX_POLLS = 150; // ~15 min
// If it never leaves "queued", the worker isn't consuming the job.
const QUEUED_STALL_POLLS = 6; // ~36 s

let pollTimer: ReturnType<typeof setTimeout> | null = null;
let pollCount = 0;
let sawRunning = false;

export const useAdminPlaylistsStore = create<AdminPlaylistsState>((set, get) => {
  function stop() {
    if (pollTimer) clearTimeout(pollTimer);
    pollTimer = null;
  }

  function schedulePoll() {
    stop();
    pollTimer = setTimeout(() => {
      pollTimer = null;
      pollCount += 1;
      void get()
        .fetch()
        .then(() => {
          const s = get().lastSync?.state;

          if (s === "running") sawRunning = true;

          if (s !== "queued" && s !== "running") return; // done / error - settled

          if (!sawRunning && pollCount >= QUEUED_STALL_POLLS) {
            set({
              pollNote:
                "The sync was queued but hasn't started — the background queue worker may be down. " +
                "Run `php artisan songs:sync` in the backend container, or check `supervisorctl status`.",
            });
            return;
          }

          if (pollCount >= MAX_POLLS) {
            set({ pollNote: "Still running — reload the page later to see the result." });
            return;
          }

          schedulePoll();
        });
    }, POLL_MS);
  }

  function beginPolling() {
    pollCount = 0;
    sawRunning = false;
    set({ pollNote: null });
    schedulePoll();
  }

  return {
    genres: [],
    playlists: [],
    poolSize: 0,
    lastSync: null,
    status: "idle",
    syncError: null,
    pollNote: null,

    stopPolling: stop,

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
      // Resume a bounded watch if a sync is still in flight (e.g. after
      // navigating back to the page) and we're not already watching.
      const s = data.last_sync?.state;
      if ((s === "queued" || s === "running") && !pollTimer && !get().pollNote) {
        beginPolling();
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

    sync: async () => {
      set({ syncError: null, pollNote: null });
      try {
        const { data } = await api.post("/api/admin/song-playlists/sync");
        if (data.last_sync) set({ lastSync: data.last_sync });
        beginPolling();
      } catch (err) {
        set({ syncError: firstValidationError(err) });
      }
    },
  };
});
