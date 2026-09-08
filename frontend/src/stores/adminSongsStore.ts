import { create } from "zustand";

import { api } from "../lib/api";

export interface AdminSong {
  id: number;
  provider_track_id: string;
  title: string;
  artist: string;
  album_art_url: string | null;
  genre: string | null;
  popularity: number;
  release_year: number | null;
  excluded: boolean;
  last_used_at: string | null;
  preview_url: string;
}

export interface AdminSongUpdate {
  title?: string;
  artist?: string;
  genre?: string | null;
  popularity?: number;
  release_year?: number | null;
  excluded?: boolean;
}

export type SongPoolFilter = "all" | "in_pool" | "removed";

interface ListMeta {
  current_page: number;
  last_page: number;
  total: number;
  pool_size: number;
  removed_count: number;
}

interface ListResponse {
  data: AdminSong[];
  meta: ListMeta;
  genres: (string | null)[];
}

interface AdminSongsState {
  songs: AdminSong[];
  meta: ListMeta | null;
  genres: (string | null)[];
  search: string;
  genre: string; // "" = all, "unknown" = genre IS NULL
  poolFilter: SongPoolFilter;
  page: number;
  status: "idle" | "loading" | "ready";
  selectedIds: number[];
  selected: AdminSong | null;
  selectedStatus: "idle" | "loading" | "ready";

  fetchSongs: () => Promise<void>;
  setSearch: (term: string) => void;
  setGenre: (genre: string) => void;
  setPoolFilter: (filter: SongPoolFilter) => void;
  setPage: (page: number) => void;

  fetchSong: (id: number) => Promise<void>;
  updateSong: (id: number, patch: AdminSongUpdate) => Promise<AdminSong>;
  bulkExclude: (ids: number[], excluded: boolean) => Promise<void>;

  toggleSelected: (id: number) => void;
  clearSelection: () => void;
}

export const useAdminSongsStore = create<AdminSongsState>((set, get) => ({
  songs: [],
  meta: null,
  genres: [],
  search: "",
  genre: "",
  poolFilter: "all",
  page: 1,
  status: "idle",
  selectedIds: [],
  selected: null,
  selectedStatus: "idle",

  fetchSongs: async () => {
    set({ status: "loading" });
    const { search, genre, poolFilter, page } = get();
    const response = await api.get<ListResponse>("/api/admin/songs", {
      params: {
        search: search || undefined,
        genre: genre || undefined,
        status: poolFilter !== "all" ? poolFilter : undefined,
        page,
      },
    });
    set({
      songs: response.data.data,
      meta: response.data.meta,
      genres: response.data.genres,
      status: "ready",
    });
  },

  // Each filter change resets to page 1 and drops the current selection
  // (those rows may no longer be visible).
  setSearch: (term) => {
    set({ search: term, page: 1, selectedIds: [] });
    void get().fetchSongs();
  },
  setGenre: (genre) => {
    set({ genre, page: 1, selectedIds: [] });
    void get().fetchSongs();
  },
  setPoolFilter: (poolFilter) => {
    set({ poolFilter, page: 1, selectedIds: [] });
    void get().fetchSongs();
  },
  setPage: (page) => {
    set({ page, selectedIds: [] });
    void get().fetchSongs();
  },

  fetchSong: async (id) => {
    set({ selectedStatus: "loading", selected: null });
    const response = await api.get<AdminSong>(`/api/admin/songs/${id}`);
    set({ selected: response.data, selectedStatus: "ready" });
  },

  updateSong: async (id, patch) => {
    const response = await api.patch<AdminSong>(`/api/admin/songs/${id}`, patch);
    set((state) => ({
      selected: state.selected?.id === id ? response.data : state.selected,
      songs: state.songs.map((s) => (s.id === id ? response.data : s)),
    }));
    return response.data;
  },

  bulkExclude: async (ids, excluded) => {
    await api.post("/api/admin/songs/bulk-exclude", { ids, excluded });
    set({ selectedIds: [] });
    await get().fetchSongs();
  },

  toggleSelected: (id) =>
    set((state) => ({
      selectedIds: state.selectedIds.includes(id)
        ? state.selectedIds.filter((x) => x !== id)
        : [...state.selectedIds, id],
    })),
  clearSelection: () => set({ selectedIds: [] }),
}));

const GENRE_LABELS: Record<string, string> = {
  pop: "Pop",
  hip_hop: "Hip hop",
  german_rap: "German rap",
  iconic: "Iconic",
};

export function genreLabel(genre: string | null): string {
  if (genre === null || genre === "" || genre === "unknown") return "Unknown";
  return GENRE_LABELS[genre] ?? genre;
}
