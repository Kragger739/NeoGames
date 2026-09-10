import { create } from "zustand";

import { api } from "../lib/api";
import { firstValidationError } from "../lib/errors";

export interface AdminDubPack {
  id: number;
  name: string;
  owner_username: string | null;
  review_status: "draft" | "pending" | "approved" | "rejected";
  review_note: string | null;
  clip_count: number;
  ready_clip_count: number;
  clips: Array<{ id: number; title: string; status: string; video_url: string | null }>;
  updated_at: string;
}

interface AdminDubPacksState {
  packs: AdminDubPack[];
  status: "idle" | "loading" | "ready";
  error: string | null;
  fetch: () => Promise<void>;
  approve: (id: number) => Promise<void>;
  reject: (id: number, note: string) => Promise<void>;
}

export const useAdminDubPacksStore = create<AdminDubPacksState>((set, get) => {
  async function reload() {
    const { data } = await api.get<{ packs: AdminDubPack[] }>("/api/admin/dub-packs");
    set({ packs: data.packs, status: "ready" });
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
    packs: [],
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

    approve: (id) => run(() => api.post(`/api/admin/dub-packs/${id}/approve`)),
    reject: (id, note) => run(() => api.post(`/api/admin/dub-packs/${id}/reject`, { note })),
  };
});
