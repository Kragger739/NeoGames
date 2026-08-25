import { FormEvent, useEffect, useState } from "react";
import { Link } from "react-router-dom";

import { firstValidationError } from "../lib/errors";
import { useFriendsStore } from "../stores/friendsStore";
import { OnlineStatusDot } from "../components/OnlineStatusDot";

export function FriendsPage() {
  const status = useFriendsStore((state) => state.status);
  const friends = useFriendsStore((state) => state.friends);
  const incomingRequests = useFriendsStore((state) => state.incomingRequests);
  const outgoingRequests = useFriendsStore((state) => state.outgoingRequests);
  const onlineUserIds = useFriendsStore((state) => state.onlineUserIds);
  const fetchFriends = useFriendsStore((state) => state.fetch);
  const sendRequest = useFriendsStore((state) => state.sendRequest);
  const accept = useFriendsStore((state) => state.accept);
  const remove = useFriendsStore((state) => state.remove);
  const connectPresence = useFriendsStore((state) => state.connectPresence);

  const [username, setUsername] = useState("");
  const [error, setError] = useState<string | null>(null);
  const [submitting, setSubmitting] = useState(false);

  useEffect(() => {
    if (status === "idle") {
      void fetchFriends();
    }
    connectPresence();
  }, [status, fetchFriends, connectPresence]);

  async function handleAdd(e: FormEvent) {
    e.preventDefault();
    setError(null);
    setSubmitting(true);
    try {
      await sendRequest(username);
      setUsername("");
    } catch (err) {
      setError(firstValidationError(err));
    } finally {
      setSubmitting(false);
    }
  }

  return (
    <div className="friends-page">
      <p>
        <Link to="/dashboard">← Dashboard</Link>
      </p>
      <h1>Friends</h1>

      <form onSubmit={handleAdd}>
        <label>
          Add a friend by username
          <input
            value={username}
            onChange={(e) => setUsername(e.target.value)}
            placeholder="username"
            required
          />
        </label>
        {error && <p className="form-error">{error}</p>}
        <button type="submit" disabled={submitting}>
          {submitting ? "Sending…" : "Send request"}
        </button>
      </form>

      {incomingRequests.length > 0 && (
        <>
          <h2>Requests</h2>
          <ul className="player-list">
            {incomingRequests.map((req) => (
              <li key={req.id}>
                <span>{req.user.username}</span>
                <span className="friend-actions">
                  <button type="button" onClick={() => void accept(req.id)}>
                    Accept
                  </button>
                  <button type="button" className="button-secondary" onClick={() => void remove(req.id)}>
                    Decline
                  </button>
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
                <span className="hint">Pending…</span>
              </li>
            ))}
          </ul>
        </>
      )}

      <h2>Your friends</h2>
      {friends.length === 0 ? (
        <p className="hint">No friends yet — add one by username above.</p>
      ) : (
        <ul className="player-list">
          {friends.map((friend) => (
            <li key={friend.id}>
              <span className="friend-name">
                <OnlineStatusDot online={onlineUserIds.has(friend.id)} />
                {friend.username}
              </span>
              <span className="hint">Level {friend.level}</span>
            </li>
          ))}
        </ul>
      )}
    </div>
  );
}
