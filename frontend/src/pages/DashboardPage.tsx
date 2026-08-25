import { useState } from "react";
import { Link, useNavigate } from "react-router-dom";

import { api } from "../lib/api";
import { firstValidationError } from "../lib/errors";
import { setPlayerId, setPlayerToken } from "../lib/playerToken";
import type { CreateRoomResponse } from "../lib/roomTypes";
import { useAuthStore } from "../stores/authStore";

export function DashboardPage() {
  const host = useAuthStore((state) => state.host);
  const logout = useAuthStore((state) => state.logout);
  const navigate = useNavigate();
  const [creating, setCreating] = useState(false);
  const [createError, setCreateError] = useState<string | null>(null);

  async function handleLogout() {
    await logout();
    navigate("/login");
  }

  // "New room" used to be a link to a settings form; now it drops you
  // straight into a live room, and settings (including mode) become
  // editable from inside the lobby itself (see RoomSettingsForm).
  async function handleNewRoom() {
    setCreateError(null);
    setCreating(true);
    try {
      const response = await api.post<CreateRoomResponse>("/api/rooms");
      setPlayerToken(response.data.player.connection_token);
      setPlayerId(response.data.player.id);
      navigate(`/rooms/${response.data.code}/lobby`);
    } catch (err) {
      setCreateError(firstValidationError(err));
      setCreating(false);
    }
  }

  return (
    <div className="dashboard-page">
      <h1>Welcome, {host?.name}</h1>
      {host && <p className="hint">Level {host.level} · {host.xp} XP</p>}
      <nav>
        <button type="button" onClick={() => void handleNewRoom()} disabled={creating}>
          {creating ? "Creating…" : "New room"}
        </button>
        <Link to="/profile">Profile</Link>
        <Link to="/friends">Friends</Link>
      </nav>
      {createError && <p className="form-error">{createError}</p>}
      <p className="hint">Songs are pulled automatically — no setup needed.</p>
      <button onClick={handleLogout}>Log out</button>
    </div>
  );
}
