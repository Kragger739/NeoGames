import { create } from "zustand";

import { api } from "../lib/api";
import { firstValidationError } from "../lib/errors";
import type { DubClipAdminRow, DubClipDetail, DubScriptPayload } from "../lib/dubTypes";

interface AdminDubClipsState {
  clips: DubClipAdminRow[];
  status: "idle" | "loading" | "ready";
  error: string | null;
  current: DubClipDetail | null;
  currentStatus: "idle" | "loading" | "ready";
  fetch: () => Promise<void>;
  fetchOne: (id: number) => Promise<void>;
  createClip: (form: FormData) => Promise<DubClipAdminRow>;
  createClipYoutube: (body: { title: string; url: string }) => Promise<DubClipAdminRow>;
  reingestClip: (id: number) => Promise<void>;
  updateClip: (id: number, form: FormData) => Promise<void>;
  saveScript: (id: number, payload: DubScriptPayload) => Promise<void>;
  publishClip: (id: number) => Promise<void>;
  unpublishClip: (id: number) => Promise<void>;
  deleteClip: (id: number) => Promise<void>;
}

export const useAdminDubClipsStore = create<AdminDubClipsState>((set, get) => {
  async function reload() {
    const { data } = await api.get<{ clips: DubClipAdminRow[] }>("/api/admin/dub-clips");
    set({ clips: data.clips, status: "ready" });
  }

  async function run<T>(fn: () => Promise<T>): Promise<T> {
    set({ error: null });
    try {
      const result = await fn();
      await reload();
      return result;
    } catch (err) {
      set({ error: firstValidationError(err) });
      throw err;
    }
  }

  return {
    clips: [],
    status: "idle",
    error: null,
    current: null,
    currentStatus: "idle",

    fetch: async () => {
      set({ status: get().status === "idle" ? "loading" : get().status, error: null });
      try {
        await reload();
      } catch (err) {
        set({ error: firstValidationError(err), status: "ready" });
      }
    },

    fetchOne: async (id) => {
      set({ currentStatus: "loading", error: null });
      try {
        const { data } = await api.get<DubClipDetail>(`/api/admin/dub-clips/${id}`);
        set({ current: data, currentStatus: "ready" });
      } catch (err) {
        set({ error: firstValidationError(err), currentStatus: "ready" });
      }
    },

    createClip: (form) =>
      run(async () => {
        const { data } = await api.post<DubClipAdminRow>("/api/admin/dub-clips", form);
        return data;
      }),

    createClipYoutube: (body) =>
      run(async () => {
        const { data } = await api.post<DubClipAdminRow>("/api/admin/dub-clips/youtube", body);
        return data;
      }),

    reingestClip: (id) => run(() => api.post(`/api/admin/dub-clips/${id}/reingest`)),

    updateClip: (id, form) => run(() => api.post(`/api/admin/dub-clips/${id}`, form)),

    saveScript: (id, payload) =>
      run(async () => {
        const { data } = await api.put<DubClipDetail>(`/api/admin/dub-clips/${id}/script`, payload);
        set({ current: data });
      }),

    publishClip: (id) => run(() => api.post(`/api/admin/dub-clips/${id}/publish`)),
    unpublishClip: (id) =>
      run(async () => {
        await api.post(`/api/admin/dub-clips/${id}/unpublish`);
        if (get().current?.id === id) await get().fetchOne(id);
      }),
    deleteClip: (id) => run(() => api.delete(`/api/admin/dub-clips/${id}`)),
  };
});
