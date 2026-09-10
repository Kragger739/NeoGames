import { type FormEvent, useState } from "react";
import { useNavigate } from "react-router-dom";
import { ArrowLeft } from "lucide-react";

import { api } from "../lib/api";
import { firstValidationError } from "../lib/errors";
import { setPlayerId, setPlayerToken } from "../lib/playerToken";
import type { CreateDubRoomResponse } from "../lib/dubTypes";
import { Button } from "../components/ui/Button";
import { Card } from "../components/ui/Card";
import { IconButton } from "../components/ui/IconButton";

export function DubLandingPage() {
  const navigate = useNavigate();
  const [lineTimerSeconds, setLineTimerSeconds] = useState(0);
  const [creating, setCreating] = useState(false);
  const [error, setError] = useState<string | null>(null);

  async function handleCreate(e: FormEvent) {
    e.preventDefault();
    setError(null);
    setCreating(true);
    try {
      const response = await api.post<CreateDubRoomResponse>("/api/dub-rooms", {
        line_timer_seconds: lineTimerSeconds,
      });
      // The host is seated as a player - keep their seat token so the lobby
      // can claim a character and record like everyone else.
      setPlayerToken(response.data.host_player.connection_token);
      setPlayerId(response.data.host_player.id);
      navigate(`/dub-rooms/${response.data.code}/lobby`);
    } catch (err) {
      setError(firstValidationError(err));
      setCreating(false);
    }
  }

  return (
    <div className="dub-landing-page">
      <div className="dub-landing-header">
        <IconButton icon={ArrowLeft} label="Back to Home" variant="ghost" onClick={() => navigate("/")} />
      </div>
      <h1>DUB TOGETHER</h1>
      <p className="hint">
        Claim a character in a short clip, record your lines in order, then watch the dub your
        group made and rate it together.
      </p>
      <Card className="dub-landing-card">
        <form onSubmit={handleCreate}>
          <label>
            Time limit per line (seconds, 0 = no limit)
            <input
              type="number"
              min={0}
              max={120}
              value={lineTimerSeconds}
              onChange={(e) => setLineTimerSeconds(Number(e.target.value))}
            />
          </label>
          <p className="hint">You can pick a clip — or upload your own — once you're in the lobby.</p>
          {error && <p className="form-error">{error}</p>}
          <Button type="submit" variant="grape" size="lg" disabled={creating}>
            {creating ? "Creating…" : "Create game"}
          </Button>
        </form>
      </Card>
    </div>
  );
}
