import axios from "axios";

import { getPlayerToken } from "./playerToken";

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
  if (playerToken) {
    config.headers.set("X-Player-Token", playerToken);
  }
  return config;
});

export async function ensureCsrfCookie() {
  await api.get("/sanctum/csrf-cookie");
}
