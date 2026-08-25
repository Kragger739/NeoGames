import { create } from "zustand";

import { api } from "../lib/api";
import { getEcho } from "../lib/echo";
import type { FriendsIndexResponse, FriendUser } from "../lib/friendTypes";
import type { PresenceMember } from "../lib/roomTypes";

interface FriendsState {
  friends: FriendUser[];
  incomingRequests: FriendsIndexResponse["incoming_requests"];
  outgoingRequests: FriendsIndexResponse["outgoing_requests"];
  onlineUserIds: Set<number>;
  status: "idle" | "loading" | "ready";
  presenceConnected: boolean;
  fetch: () => Promise<void>;
  sendRequest: (username: string) => Promise<void>;
  accept: (friendshipId: number) => Promise<void>;
  remove: (friendshipId: number) => Promise<void>;
  connectPresence: () => void;
}

/**
 * One global presence channel every logged-in visitor joins, rather than a
 * channel per friend pair (doesn't scale, and Reverb has no clean "who's
 * subscribed" introspection outside what a presence channel gives for
 * free). The member roster is cross-referenced against the friends list
 * client-side to decide which online dots to light up.
 */
export const useFriendsStore = create<FriendsState>((set, get) => ({
  friends: [],
  incomingRequests: [],
  outgoingRequests: [],
  onlineUserIds: new Set(),
  status: "idle",
  presenceConnected: false,

  fetch: async () => {
    set({ status: "loading" });
    const response = await api.get<FriendsIndexResponse>("/api/friends");
    set({
      friends: response.data.friends,
      incomingRequests: response.data.incoming_requests,
      outgoingRequests: response.data.outgoing_requests,
      status: "ready",
    });
  },

  sendRequest: async (username: string) => {
    await api.post("/api/friends", { username });
    await get().fetch();
  },

  accept: async (friendshipId: number) => {
    await api.post(`/api/friends/${friendshipId}/accept`);
    await get().fetch();
  },

  remove: async (friendshipId: number) => {
    await api.delete(`/api/friends/${friendshipId}`);
    await get().fetch();
  },

  connectPresence: () => {
    if (get().presenceConnected) return;
    set({ presenceConnected: true });

    const channel = getEcho().join("online-users");

    channel.here((members: PresenceMember[]) => {
      set({ onlineUserIds: new Set(members.map((m) => Number(m.id))) });
    });
    channel.joining((member: PresenceMember) => {
      set((state) => ({
        onlineUserIds: new Set(state.onlineUserIds).add(Number(member.id)),
      }));
    });
    channel.leaving((member: PresenceMember) => {
      set((state) => {
        const next = new Set(state.onlineUserIds);
        next.delete(Number(member.id));
        return { onlineUserIds: next };
      });
    });
  },
}));
