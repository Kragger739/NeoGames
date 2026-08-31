import { useEffect, useState } from "react";
import { useNavigate } from "react-router-dom";
import { ArrowLeft, CalendarDays, Gamepad2, Lock } from "lucide-react";

import { api } from "../lib/api";
import { firstValidationError } from "../lib/errors";
import type { CreateRoomResponse } from "../lib/roomTypes";
import { setPlayerId, setPlayerToken } from "../lib/playerToken";
import { useAuthStore } from "../stores/authStore";
import { useUnlockStore } from "../stores/unlockStore";
import { Button } from "../components/ui/Button";
import { IconButton } from "../components/ui/IconButton";
import { PartyNote } from "../components/illustrations/PartyNote";

interface DailyStatus {
  date: string;
  played: boolean;
  finished: boolean;
  best_score: number | null;
}

interface DailyStartResponse {
  code: string;
  player: { id: number; connection_token: string; nickname: string };
}

export function SonglePage() {
  const host = useAuthStore((state) => state.host);
  const fetchUnlocks = useUnlockStore((state) => state.fetch);
  const requiredLevel = useUnlockStore((state) => state.requiredLevel);
  const navigate = useNavigate();

  const [creating, setCreating] = useState(false);
  const [startingDaily, setStartingDaily] = useState(false);
  const [createError, setCreateError] = useState<string | null>(null);
  const [daily, setDaily] = useState<DailyStatus | null>(null);

  useEffect(() => {
    void fetchUnlocks();
    api
      .get<DailyStatus>("/api/daily")
      .then((res) => setDaily(res.data))
      .catch(() => setDaily(null));
  }, [fetchUnlocks]);

  const gameNightLevel = requiredLevel("game_night");
  const gameNightLocked = host != null && host.level < gameNightLevel;

  async function handleDaily() {
    setCreateError(null);
    setStartingDaily(true);
    try {
      const response = await api.post<DailyStartResponse>("/api/daily/start");
      setPlayerToken(response.data.player.connection_token);
      setPlayerId(response.data.player.id);
      // The room is already active - the lobby immediately forwards to /play.
      navigate(`/rooms/${response.data.code}/lobby`);
    } catch (err) {
      setCreateError(firstValidationError(err));
      setStartingDaily(false);
    }
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

      <Button
        variant="primary"
        size="lg"
        onClick={() => void handleDaily()}
        disabled={startingDaily || daily?.played === true}
      >
        {startingDaily ? (
          "Starting…"
        ) : daily?.played ? (
          "Daily done — come back tomorrow"
        ) : (
          <>
            <CalendarDays size={20} strokeWidth={2.5} />
            Daily
          </>
        )}
      </Button>
      <p className="hint">Five fixed songs, the same for everyone, solo. One run a day.</p>

      <div className={gameNightLocked ? "songle-lock-wrap is-locked" : "songle-lock-wrap"}>
        <Button
          variant="primary"
          size="lg"
          onClick={() => void handleNewRoom()}
          disabled={creating || gameNightLocked}
        >
          {creating ? (
            "Setting up…"
          ) : (
            <>
              <Gamepad2 size={20} strokeWidth={2.5} />
              Start a game night
            </>
          )}
        </Button>
        {gameNightLocked && (
          <div className="songle-lock-overlay" aria-hidden="true">
            <Lock size={22} strokeWidth={2.5} />
            <span>Unlocks at level {gameNightLevel}</span>
          </div>
        )}
      </div>
      {createError && <p className="form-error">{createError}</p>}
      <p className="hint">Songs are pulled automatically — no setup needed.</p>
    </div>
  );
}
