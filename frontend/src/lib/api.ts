import axios from "axios";

import { getPlayerToken } from "./playerToken";

// Lets a specific call (see lib/echo.ts's playerAwareAuthorizer) opt out of
// the automatic X-Player-Token attachment below - without this, that
// per-request override gets silently clobbered right back by this same
// interceptor running afterward.
declare module "axios" {
  export interface AxiosRequestConfig {
    skipPlayerToken?: boolean;
  }
}

const apiUrl = import.meta.env.VITE_API_URL as string;

export const api = axios.create({
  baseURL: apiUrl,
  withCredentials: true,
  withXSRFToken: true,
  headers: {
    Accept: "application/json",
  },
});

api.interceptors.request.use((config) => {
  const playerToken = getPlayerToken();
  if (playerToken && !config.skipPlayerToken) {
    config.headers.set("X-Player-Token", playerToken);
  }
  return config;
});

export async function ensureCsrfCookie() {
  await api.get("/sanctum/csrf-cookie");
}
