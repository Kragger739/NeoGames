import { useEffect, useState } from "react";
import { useNavigate } from "react-router-dom";

import { api } from "../lib/api";
import { getEcho } from "../lib/echo";
import { setPlayerToken } from "../lib/playerToken";
import { useAuthStore } from "../stores/authStore";

interface RoomInviteNotification {
  from_user_id: number;
  from_username: string;
  room_code: string;
}

/**
 * Mounted once at the app shell so an invite can arrive on any page, not
 * just the Friends page. Per DESIGN.md's Shadow-Means-Floating Rule, this
 * is the system's second legitimate use of the Overlay Lift shadow
 * (alongside the guess-autocomplete dropdown) - a genuinely floating
 * element, not a new exception to the rule.
 */
export function RoomInviteToast() {
  const host = useAuthStore((state) => state.host);
  const navigate = useNavigate();
  const [invite, setInvite] = useState<RoomInviteNotification | null>(null);
  const [joining, setJoining] = useState(false);

  useEffect(() => {
    if (!host) return;

    const channel = getEcho().private(`App.Models.User.${host.id}`);
    channel.notification((notification: RoomInviteNotification) => {
      setInvite(notification);
    });

    return () => {
      getEcho().leave(`App.Models.User.${host.id}`);
    };
  }, [host]);

  if (!invite) return null;

  async function handleJoin() {
    if (!invite) return;
    setJoining(true);
    try {
      const response = await api.post<{ connection_token: string; room_code: string }>(
        `/api/rooms/${invite.room_code}/join`,
      );
      setPlayerToken(response.data.connection_token);
      navigate(`/rooms/${response.data.room_code}/lobby`);
      setInvite(null);
    } finally {
      setJoining(false);
    }
  }

  return (
    <div className="room-invite-toast">
      <p>
        <strong>{invite.from_username}</strong> invited you to room {invite.room_code}
      </p>
      <div className="room-invite-toast-actions">
        <button type="button" onClick={() => void handleJoin()} disabled={joining}>
          {joining ? "Joining…" : "Join"}
        </button>
        <button type="button" className="button-secondary" onClick={() => setInvite(null)}>
          Dismiss
        </button>
      </div>
    </div>
  );
}
