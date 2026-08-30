import { useEffect, useRef, useState } from "react";
import { useNavigate } from "react-router-dom";
import { PartyPopper } from "lucide-react";

import { api } from "../lib/api";
import { getEcho } from "../lib/echo";
import { setPlayerId, setPlayerToken } from "../lib/playerToken";
import { useAuthStore } from "../stores/authStore";
import { Button } from "./ui/Button";

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
  const subscribedHostId = useRef<number | null>(null);

  useEffect(() => {
    if (!host) return;
    // Guard against resubscribing on every `host` reference change (e.g.
    // profile edits create a new host object) - this channel is shared
    // with friendsStore.connectNotifications()'s own permanent
    // subscription, and leaving/recreating it here would orphan that
    // listener, since Echo.leave() tears the channel down for every
    // subscriber, not just this component's own listener.
    if (subscribedHostId.current === host.id) return;
    subscribedHostId.current = host.id;

    const channel = getEcho().private(`App.Models.User.${host.id}`);
    // This channel also carries friend-request notifications (see
    // friendsStore.ts's connectNotifications()) - both listeners coexist
    // independently, so this one has to ignore types it doesn't own
    // instead of assuming every notification here is a room invite.
    channel.notification((notification: RoomInviteNotification & { type?: string }) => {
      if (notification.type === "room.invite") {
        setInvite(notification);
      }
    });
  }, [host]);

  if (!invite) return null;

  async function handleJoin() {
    if (!invite) return;
    setJoining(true);
    try {
      const response = await api.post<{ id: number; connection_token: string; room_code: string }>(
        `/api/rooms/${invite.room_code}/join`,
      );
      setPlayerToken(response.data.connection_token);
      setPlayerId(response.data.id);
      navigate(`/rooms/${response.data.room_code}/lobby`);
      setInvite(null);
    } finally {
      setJoining(false);
    }
  }

  return (
    <div className="room-invite-toast">
      <p>
        <PartyPopper size={16} strokeWidth={2.5} />
        <strong>{invite.from_username}</strong> invited you to room {invite.room_code}
      </p>
      <div className="room-invite-toast-actions">
        <Button onClick={() => void handleJoin()} disabled={joining}>
          {joining ? "Joining…" : "Join"}
        </Button>
        <Button variant="ghost" onClick={() => setInvite(null)}>
          Dismiss
        </Button>
      </div>
    </div>
  );
}
