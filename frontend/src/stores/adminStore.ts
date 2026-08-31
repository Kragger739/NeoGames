import { create } from "zustand";

import { api } from "../lib/api";
import type { AvatarData } from "../lib/avatarData";

export interface AdminUser {
  id: number;
  name: string;
  username: string | null;
  email: string;
  email_verified: boolean;
  provider: string | null;
  xp: number;
  level: number;
  is_admin: boolean;
  banned_at: string | null;
  ban_reason: string | null;
  created_at: string | null;
  avatar: AvatarData;
  season_pass: boolean;
}

export interface AdminUserUpdate {
  name: string;
  username: string;
  email: string;
  email_verified: boolean;
  is_admin: boolean;
}

interface ListMeta {
  current_page: number;
  last_page: number;
  total: number;
}

interface AdminState {
  users: AdminUser[];
  meta: ListMeta | null;
  search: string;
  page: number;
  status: "idle" | "loading" | "ready";
  selected: AdminUser | null;
  selectedStatus: "idle" | "loading" | "ready";
  fetchUsers: () => Promise<void>;
  setSearch: (term: string) => void;
  setPage: (page: number) => void;
  fetchUser: (id: number) => Promise<void>;
  updateUser: (id: number, payload: AdminUserUpdate) => Promise<AdminUser>;
  deleteUser: (id: number) => Promise<void>;
  banUser: (id: number, reason: string) => Promise<AdminUser>;
  unbanUser: (id: number) => Promise<AdminUser>;
  resetXp: (id: number) => Promise<AdminUser>;
  setSeasonPass: (id: number, granted: boolean) => Promise<AdminUser>;
}

interface ListResponse {
  data: AdminUser[];
  meta: ListMeta;
}

export const useAdminStore = create<AdminState>((set, get) => ({
  users: [],
  meta: null,
  search: "",
  page: 1,
  status: "idle",
  selected: null,
  selectedStatus: "idle",

  fetchUsers: async () => {
    set({ status: "loading" });
    const { search, page } = get();
    const response = await api.get<ListResponse>("/api/admin/users", {
      params: { search: search || undefined, page },
    });
    set({ users: response.data.data, meta: response.data.meta, status: "ready" });
  },

  setSearch: (term) => {
    set({ search: term, page: 1 });
    void get().fetchUsers();
  },

  setPage: (page) => {
    set({ page });
    void get().fetchUsers();
  },

  fetchUser: async (id) => {
    set({ selectedStatus: "loading", selected: null });
    const response = await api.get<AdminUser>(`/api/admin/users/${id}`);
    set({ selected: response.data, selectedStatus: "ready" });
  },

  updateUser: async (id, payload) => {
    const response = await api.patch<AdminUser>(`/api/admin/users/${id}`, payload);
    set((state) => ({
      selected: response.data,
      users: state.users.map((u) => (u.id === id ? response.data : u)),
    }));
    return response.data;
  },

  deleteUser: async (id) => {
    await api.delete(`/api/admin/users/${id}`);
    set((state) => ({ users: state.users.filter((u) => u.id !== id) }));
  },

  banUser: async (id, reason) => {
    const response = await api.post<AdminUser>(`/api/admin/users/${id}/ban`, {
      reason: reason || undefined,
    });
    set((state) => ({
      selected: response.data,
      users: state.users.map((u) => (u.id === id ? response.data : u)),
    }));
    return response.data;
  },

  unbanUser: async (id) => {
    const response = await api.post<AdminUser>(`/api/admin/users/${id}/unban`);
    set((state) => ({
      selected: response.data,
      users: state.users.map((u) => (u.id === id ? response.data : u)),
    }));
    return response.data;
  },

  resetXp: async (id) => {
    const response = await api.post<AdminUser>(`/api/admin/users/${id}/reset-xp`);
    set((state) => ({
      selected: response.data,
      users: state.users.map((u) => (u.id === id ? response.data : u)),
    }));
    return response.data;
  },

  setSeasonPass: async (id, granted) => {
    const response = await api.post<AdminUser>(`/api/admin/users/${id}/season-pass`, { granted });
    set((state) => ({
      selected: response.data,
      users: state.users.map((u) => (u.id === id ? response.data : u)),
    }));
    return response.data;
  },
}));
