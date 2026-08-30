import { create } from "zustand";

import { api, ensureCsrfCookie } from "../lib/api";
import type { AvatarData } from "../lib/avatarData";

export interface Host {
  id: number;
  name: string;
  username: string | null;
  email: string;
  email_verified: boolean;
  /** Set when the account was created via Google/Discord OAuth. */
  provider: string | null;
  /** Grants the /admin area and the public admin badge. */
  is_admin: boolean;
  xp: number;
  level: number;
  avatar_url: string | null;
  avatar: AvatarData;
}

interface AuthState {
  host: Host | null;
  status: "idle" | "loading" | "ready";
  fetchHost: () => Promise<void>;
  /** Re-pull /api/user without flipping `status` - used after equipping cosmetics. */
  refreshHost: () => Promise<void>;
  register: (
    name: string,
    email: string,
    password: string,
    passwordConfirmation: string,
    acceptedTerms: boolean,
  ) => Promise<void>;
  login: (email: string, password: string) => Promise<void>;
  /** Submit the emailed 6-digit code; on success the host becomes verified. */
  verifyEmail: (code: string) => Promise<void>;
  /** Ask the API to email a fresh verification code. */
  resendVerificationCode: () => Promise<void>;
  /** Email a password-reset link (always resolves - no account enumeration). */
  requestPasswordReset: (email: string) => Promise<void>;
  resetPassword: (payload: {
    token: string;
    email: string;
    password: string;
    passwordConfirmation: string;
  }) => Promise<void>;
  logout: () => Promise<void>;
  updateUsername: (username: string) => Promise<void>;
  uploadAvatar: (file: File) => Promise<void>;
  removeAvatar: () => Promise<void>;
  /** Permanently delete the account; `secret` is the password or, for OAuth accounts, the username. */
  deleteAccount: (secret: string) => Promise<void>;
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

  refreshHost: async () => {
    try {
      const response = await api.get<Host>("/api/user");
      set({ host: response.data });
    } catch {
      // Keep the current host on a transient failure.
    }
  },

  register: async (name, email, password, passwordConfirmation, acceptedTerms) => {
    await ensureCsrfCookie();
    const response = await api.post<Host>("/api/register", {
      name,
      email,
      password,
      password_confirmation: passwordConfirmation,
      accepted_terms: acceptedTerms,
    });
    set({ host: response.data, status: "ready" });
  },

  login: async (email, password) => {
    await ensureCsrfCookie();
    const response = await api.post<Host>("/api/login", { email, password });
    set({ host: response.data, status: "ready" });
  },

  verifyEmail: async (code) => {
    await ensureCsrfCookie();
    const response = await api.post<Host>("/api/email/verify", { code });
    set({ host: response.data, status: "ready" });
  },

  resendVerificationCode: async () => {
    await api.post("/api/email/verification-code");
  },

  requestPasswordReset: async (email) => {
    await ensureCsrfCookie();
    await api.post("/api/forgot-password", { email });
  },

  resetPassword: async ({ token, email, password, passwordConfirmation }) => {
    await ensureCsrfCookie();
    await api.post("/api/reset-password", {
      token,
      email,
      password,
      password_confirmation: passwordConfirmation,
    });
  },

  logout: async () => {
    await api.post("/api/logout");
    set({ host: null, status: "ready" });
  },

  updateUsername: async (username) => {
    const response = await api.patch<Host>("/api/profile", { username });
    set({ host: response.data });
  },

  uploadAvatar: async (file) => {
    const formData = new FormData();
    formData.append("avatar", file);
    const response = await api.post<Host>("/api/profile/avatar", formData);
    set({ host: response.data });
  },

  removeAvatar: async () => {
    const response = await api.delete<Host>("/api/profile/avatar");
    set({ host: response.data });
  },

  deleteAccount: async (secret) => {
    // Backend picks the right check from the account type; sending both
    // keys keeps the caller from needing to branch.
    await api.delete("/api/user", {
      data: { password: secret, confirmation: secret },
    });
    set({ host: null, status: "ready" });
  },
}));
