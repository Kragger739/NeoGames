import type { AvatarData } from "./avatarData";

export interface FriendUser {
  id: number;
  username: string;
  level: number;
  xp: number;
  current_room_code: string | null;
  avatar: AvatarData;
}

export interface FriendRequest {
  id: number;
  user: FriendUser;
}

/** A hit from GET /api/friends/search — same shape as presentUser() server-side. */
export interface FriendSearchResult {
  id: number;
  username: string;
  level: number;
  xp: number;
  avatar: AvatarData;
}

export interface FriendsIndexResponse {
  friends: FriendUser[];
  incoming_requests: FriendRequest[];
  outgoing_requests: FriendRequest[];
}
