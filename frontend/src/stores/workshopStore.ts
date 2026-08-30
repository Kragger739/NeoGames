import { create } from "zustand";

import { api } from "../lib/api";
import type {
  DatasetDetail,
  DatasetLanguage,
  DatasetsIndex,
  DatasetType,
} from "../lib/workshopTypes";

type QuestionInput = { text: string; correct_answer: string; category: string };

interface WorkshopState {
  status: "idle" | "loading" | "ready";
  index: DatasetsIndex;
  currentStatus: "idle" | "loading" | "ready";
  current: DatasetDetail | null;
  saving: boolean;

  fetchIndex: (type?: DatasetType) => Promise<void>;
  fetchOne: (id: number) => Promise<void>;
  create: (payload: { name: string; type: DatasetType; language?: DatasetLanguage }) => Promise<DatasetDetail>;
  update: (id: number, patch: { name?: string; visibility?: string }) => Promise<void>;
  remove: (id: number) => Promise<void>;
  duplicate: (id: number) => Promise<DatasetDetail>;

  addQuestion: (id: number, q: QuestionInput) => Promise<void>;
  updateQuestion: (id: number, questionId: number, q: QuestionInput) => Promise<void>;
  deleteQuestion: (id: number, questionId: number) => Promise<void>;
  reorderQuestions: (id: number, ids: number[]) => Promise<void>;

  importPlaylist: (id: number, playlist: string) => Promise<void>;
  removeTrack: (id: number, trackId: number) => Promise<void>;
}

export const useWorkshopStore = create<WorkshopState>((set, get) => ({
  status: "idle",
  index: { mine: [], community: [] },
  currentStatus: "idle",
  current: null,
  saving: false,

  fetchIndex: async (type) => {
    set({ status: "loading" });
    try {
      const response = await api.get<DatasetsIndex>("/api/datasets", {
        params: type ? { type } : undefined,
      });
      set({ index: response.data, status: "ready" });
    } catch {
      set({ status: "ready" });
    }
  },

  fetchOne: async (id) => {
    set({ currentStatus: "loading", current: null });
    try {
      const response = await api.get<DatasetDetail>(`/api/datasets/${id}`);
      set({ current: response.data, currentStatus: "ready" });
    } catch {
      set({ currentStatus: "ready" });
    }
  },

  create: async (payload) => {
    set({ saving: true });
    try {
      const response = await api.post<DatasetDetail>("/api/datasets", payload);
      await get().fetchIndex();
      return response.data;
    } finally {
      set({ saving: false });
    }
  },

  update: async (id, patch) => {
    set({ saving: true });
    try {
      const response = await api.patch<DatasetDetail>(`/api/datasets/${id}`, patch);
      set({ current: response.data });
      await get().fetchIndex();
    } finally {
      set({ saving: false });
    }
  },

  remove: async (id) => {
    await api.delete(`/api/datasets/${id}`);
    await get().fetchIndex();
  },

  duplicate: async (id) => {
    set({ saving: true });
    try {
      const response = await api.post<DatasetDetail>(`/api/datasets/${id}/duplicate`);
      await get().fetchIndex();
      return response.data;
    } finally {
      set({ saving: false });
    }
  },

  addQuestion: async (id, q) => {
    set({ saving: true });
    try {
      const response = await api.post<DatasetDetail>(`/api/datasets/${id}/questions`, q);
      set({ current: response.data });
    } finally {
      set({ saving: false });
    }
  },

  updateQuestion: async (id, questionId, q) => {
    set({ saving: true });
    try {
      const response = await api.patch<DatasetDetail>(`/api/datasets/${id}/questions/${questionId}`, q);
      set({ current: response.data });
    } finally {
      set({ saving: false });
    }
  },

  deleteQuestion: async (id, questionId) => {
    const response = await api.delete<DatasetDetail>(`/api/datasets/${id}/questions/${questionId}`);
    set({ current: response.data });
  },

  reorderQuestions: async (id, ids) => {
    const response = await api.patch<DatasetDetail>(`/api/datasets/${id}/questions/reorder`, { ids });
    set({ current: response.data });
  },

  importPlaylist: async (id, playlist) => {
    set({ saving: true });
    try {
      const response = await api.post<DatasetDetail>(`/api/datasets/${id}/import`, { playlist });
      set({ current: response.data });
    } finally {
      set({ saving: false });
    }
  },

  removeTrack: async (id, trackId) => {
    const response = await api.delete<DatasetDetail>(`/api/datasets/${id}/tracks/${trackId}`);
    set({ current: response.data });
  },
}));
