export interface FriendUser {
  id: number;
  username: string;
  level: number;
}

export interface FriendRequest {
  id: number;
  user: FriendUser;
}

export interface FriendsIndexResponse {
  friends: FriendUser[];
  incoming_requests: FriendRequest[];
  outgoing_requests: FriendRequest[];
}
