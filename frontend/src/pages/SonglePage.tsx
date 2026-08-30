import { useState } from "react";
import { useNavigate } from "react-router-dom";
import { ArrowLeft, Gamepad2 } from "lucide-react";

import { api } from "../lib/api";
import { firstValidationError } from "../lib/errors";
import type { CreateRoomResponse } from "../lib/roomTypes";
import { setPlayerId, setPlayerToken } from "../lib/playerToken";
import { useAuthStore } from "../stores/authStore";
import { Button } from "../components/ui/Button";
import { IconButton } from "../components/ui/IconButton";
import { PartyNote } from "../components/illustrations/PartyNote";

export function SonglePage() {
  const host = useAuthStore((state) => state.host);
  const navigate = useNavigate();
  const [creating, setCreating] = useState(false);
  const [createError, setCreateError] = useState<string | null>(null);

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
    <div className="songle-page">
      <div className="songle-page-header">
        <IconButton
          icon={ArrowLeft}
          label="Back to Home"
          variant="danger"
          onClick={() => navigate("/")}
        />
      </div>
      <p className="dashboard-wordmark">Songle</p>
      <PartyNote className="dashboard-hero" />
      {host && <h1>Hey, {host.name}!</h1>}
      <Button variant="primary" size="lg" onClick={() => void handleNewRoom()} disabled={creating}>
        {creating ? (
          "Setting up…"
        ) : (
          <>
            <Gamepad2 size={20} strokeWidth={2.5} />
            Start a game night
          </>
        )}
      </Button>
      {createError && <p className="form-error">{createError}</p>}
      <p className="hint">Songs are pulled automatically — no setup needed.</p>
    </div>
  );
}
