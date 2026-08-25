import { create } from "zustand";

import { api, ensureCsrfCookie } from "../lib/api";

export interface Host {
  id: number;
  name: string;
  username: string | null;
  email: string;
  xp: number;
  level: number;
}

interface AuthState {
  host: Host | null;
  status: "idle" | "loading" | "ready";
  fetchHost: () => Promise<void>;
  register: (
    name: string,
    email: string,
    password: string,
    passwordConfirmation: string,
  ) => Promise<void>;
  login: (email: string, password: string) => Promise<void>;
  logout: () => Promise<void>;
  updateUsername: (username: string) => Promise<void>;
}

export const useAuthStore = create<AuthState>((set) => ({
  host: null,
  status: "idle",

  fetchHost: async () => {
    set({ status: "loading" });
    try {
      const response = await api.get<Host>("/api/user");
      set({ host: response.data, status: "ready" });
    } catch {
      set({ host: null, status: "ready" });
    }
  },

  register: async (name, email, password, passwordConfirmation) => {
    await ensureCsrfCookie();
    const response = await api.post<Host>("/api/register", {
      name,
      email,
      password,
      password_confirmation: passwordConfirmation,
    });
    set({ host: response.data, status: "ready" });
  },

  login: async (email, password) => {
    await ensureCsrfCookie();
    const response = await api.post<Host>("/api/login", { email, password });
    set({ host: response.data, status: "ready" });
  },

  logout: async () => {
    await api.post("/api/logout");
    set({ host: null, status: "ready" });
  },

  updateUsername: async (username) => {
    const response = await api.patch<Host>("/api/profile", { username });
    set({ host: response.data });
  },
}));
