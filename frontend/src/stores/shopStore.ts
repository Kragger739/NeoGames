import { create } from "zustand";

import { api } from "../lib/api";
import { firstValidationError } from "../lib/errors";
import { useAuthStore } from "./authStore";

export interface ShopArtist {
  id: number;
  name: string;
  image_url: string | null;
  price: number;
  owned: boolean;
}

interface ShopResponse {
  neo_coins: number;
  artists: ShopArtist[];
}

interface BuyResponse {
  neo_coins: number;
  owned: boolean;
}

interface ShopState {
  status: "idle" | "loading" | "ready";
  neoCoins: number;
  artists: ShopArtist[];
  error: string | null;
  buyingId: number | null;
  fetch: () => Promise<void>;
  buy: (id: number) => Promise<void>;
}

export const useShopStore = create<ShopState>((set, get) => ({
  status: "idle",
  neoCoins: 0,
  artists: [],
  error: null,
  buyingId: null,

  fetch: async () => {
    set({ status: get().status === "idle" ? "loading" : get().status, error: null });
    try {
      const { data } = await api.get<ShopResponse>("/api/shop");
      set({ neoCoins: data.neo_coins, artists: data.artists, status: "ready" });
    } catch (err) {
      set({ error: firstValidationError(err), status: "ready" });
    }
  },

  buy: async (id) => {
    set({ error: null, buyingId: id });
    try {
      const { data } = await api.post<BuyResponse>(`/api/shop/iconic-artists/${id}/buy`);
      set((state) => ({
        neoCoins: data.neo_coins,
        artists: state.artists.map((a) => (a.id === id ? { ...a, owned: data.owned } : a)),
      }));
      // Keep the header balance (authStore.host.neo_coins) in sync.
      await useAuthStore.getState().refreshHost();
    } catch (err) {
      set({ error: firstValidationError(err) });
    } finally {
      set({ buyingId: null });
    }
  },
}));
