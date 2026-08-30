import { useEffect, useState } from "react";
import { Link, useNavigate } from "react-router-dom";

import { api } from "../lib/api";
import { firstValidationError } from "../lib/errors";
import { setPlayerId, setPlayerToken } from "../lib/playerToken";
import { useFriendsStore } from "../stores/friendsStore";
import { FriendSearch } from "../components/FriendSearch";
import { OnlineStatusDot } from "../components/OnlineStatusDot";
import { Avatar } from "../components/ui/Avatar";
import { Button } from "../components/ui/Button";
import { EmptyFriends } from "../components/illustrations/EmptyFriends";

export function FriendsPage() {
  const navigate = useNavigate();
  const status = useFriendsStore((state) => state.status);
  const friends = useFriendsStore((state) => state.friends);
  const incomingRequests = useFriendsStore((state) => state.incomingRequests);
  const outgoingRequests = useFriendsStore((state) => state.outgoingRequests);
  const onlineUserIds = useFriendsStore((state) => state.onlineUserIds);
  const fetchFriends = useFriendsStore((state) => state.fetch);
  const accept = useFriendsStore((state) => state.accept);
  const remove = useFriendsStore((state) => state.remove);
  const connectPresence = useFriendsStore((state) => state.connectPresence);
  const connectNotifications = useFriendsStore((state) => state.connectNotifications);

  const [joining, setJoining] = useState(false);
  const [joinError, setJoinError] = useState<string | null>(null);

  useEffect(() => {
    if (status === "idle") {
      void fetchFriends();
    }
    connectPresence();
    // Live-refreshes incoming/outgoing requests if one arrives while
    // already sitting on this page, instead of only showing up on the
    // next visit/reload.
    connectNotifications();
  }, [status, fetchFriends, connectPresence, connectNotifications]);

  async function joinRoom(code: string) {
    setJoinError(null);
    setJoining(true);
    try {
      const response = await api.post<{ id: number; connection_token: string; room_code: string }>(
        `/api/rooms/${code}/join`,
      );
      setPlayerToken(response.data.connection_token);
      setPlayerId(response.data.id);
      navigate(`/rooms/${response.data.room_code}/lobby`);
    } catch (err) {
      // Race: the friends list is refresh-based only, so the room may have
      // started, finished, or filled between the last fetch and this click.
      setJoinError(firstValidationError(err));
      setJoining(false);
    }
  }

  return (
    <div className="friends-page">
      <p>
        <Link to="/">← Home</Link>
      </p>
      <h1>Friends</h1>

      <FriendSearch />

      {incomingRequests.length > 0 && (
        <>
          <h2>Requests</h2>
          <ul className="player-list">
            {incomingRequests.map((req) => (
              <li key={req.id}>
                <span>{req.user.username}</span>
                <span className="friend-actions">
                  <Button variant="turquoise" onClick={() => void accept(req.id)}>
                    Accept
                  </Button>
                  <Button variant="ghost" onClick={() => void remove(req.id)}>
                    Decline
                  </Button>
                </span>
              </li>
            ))}
          </ul>
        </>
      )}

      {outgoingRequests.length > 0 && (
        <>
          <h2>Sent</h2>
          <ul className="player-list">
            {outgoingRequests.map((req) => (
              <li key={req.id}>
                <span>{req.user.username}</span>
                <span className="friend-actions">
                  <span className="hint">Pending…</span>
                  <Button variant="ghost" onClick={() => void remove(req.id)}>
                    Cancel
                  </Button>
                </span>
              </li>
            ))}
          </ul>
        </>
      )}

      <h2>Friends</h2>
      {friends.length === 0 ? (
        <div className="empty-state">
          <EmptyFriends />
          <p className="hint">No friends yet — search for someone above.</p>
        </div>
      ) : (
        <>
          {joinError && <p className="form-error">{joinError}</p>}
          <ul className="player-list">
            {friends.map((friend) => (
              <li key={friend.id}>
                <span className="friend-name">
                  <OnlineStatusDot online={onlineUserIds.has(friend.id)} />
                  <Avatar data={friend.avatar} size="xs" animated={false} />
                  {friend.username.charAt(0).toUpperCase()}
                  {friend.username.slice(1)}
                </span>
                <span className="friend-actions">
                  <span className="hint">Level {friend.level}</span>
                  {friend.current_room_code && onlineUserIds.has(friend.id) && (
                    <Button
                      variant="turquoise"
                      disabled={joining}
                      onClick={() => void joinRoom(friend.current_room_code!)}
                    >
                      {joining ? "Joining…" : "Join"}
                    </Button>
                  )}
                </span>
              </li>
            ))}
          </ul>
        </>
      )}
    </div>
  );
}
