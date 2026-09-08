import { create } from "zustand";

import { api } from "../lib/api";
import { firstValidationError } from "../lib/errors";

export type IconicFetchStatus = "pending" | "discovering" | "seeding" | "done" | "failed";

export interface AdminIconicArtist {
  id: number;
  name: string;
  image_url: string | null;
  enabled: boolean;
  sort_order: number;
  price: number;
  free_this_week: boolean;
  pool_size: number;
  fetch_status: IconicFetchStatus;
  fetch_total: number;
  fetch_resolved: number;
  fetched_playable: number;
  top20_count: number;
  fetch_error: string | null;
  fetched_at: string | null;
}

export const ICONIC_FETCH_IN_FLIGHT: IconicFetchStatus[] = ["pending", "discovering", "seeding"];

interface AdminIconicArtistsState {
  artists: AdminIconicArtist[];
  status: "idle" | "loading" | "ready";
  error: string | null;
  fetch: () => Promise<void>;
  createArtist: (form: FormData) => Promise<void>;
  updateArtist: (id: number, form: FormData) => Promise<void>;
  deleteArtist: (id: number) => Promise<void>;
  refetch: (id: number) => Promise<void>;
}

export const useAdminIconicArtistsStore = create<AdminIconicArtistsState>((set, get) => {
  async function reload() {
    const { data } = await api.get<{ artists: AdminIconicArtist[] }>("/api/admin/iconic-artists");
    set({ artists: data.artists, status: "ready" });
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
    artists: [],
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

    createArtist: (form) => run(() => api.post("/api/admin/iconic-artists", form)),
    updateArtist: (id, form) => run(() => api.post(`/api/admin/iconic-artists/${id}`, form)),
    deleteArtist: (id) => run(() => api.delete(`/api/admin/iconic-artists/${id}`)),
    refetch: (id) => run(() => api.post(`/api/admin/iconic-artists/${id}/refetch`)),
  };
});
