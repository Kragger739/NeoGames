import { type FormEvent, useEffect, useState } from "react";
import { useNavigate, useSearchParams } from "react-router-dom";
import { ArrowLeft } from "lucide-react";

import { api } from "../lib/api";
import { firstValidationError } from "../lib/errors";
import { setPlayerId, setPlayerToken } from "../lib/playerToken";
import type { CreateDubRoomResponse } from "../lib/dubTypes";
import type { DatasetSummary, DatasetsIndex } from "../lib/workshopTypes";
import { Button } from "../components/ui/Button";
import { Card } from "../components/ui/Card";
import { IconButton } from "../components/ui/IconButton";

type PlayerMode = "solo" | "multiplayer";

export function DubLandingPage() {
  const navigate = useNavigate();
  const [searchParams] = useSearchParams();
  const initialPack = searchParams.get("dataset");

  const [playerMode, setPlayerMode] = useState<PlayerMode>("multiplayer");
  const [lineTimerSeconds, setLineTimerSeconds] = useState(0);
  const [packId, setPackId] = useState<number | null>(
    initialPack && /^\d+$/.test(initialPack) ? Number(initialPack) : null,
  );
  const [myPacks, setMyPacks] = useState<DatasetSummary[]>([]);
  const [communityPacks, setCommunityPacks] = useState<DatasetSummary[]>([]);
  const [creating, setCreating] = useState(false);
  const [error, setError] = useState<string | null>(null);

  const solo = playerMode === "solo";

  useEffect(() => {
    let cancelled = false;
    api
      .get<DatasetsIndex>("/api/datasets", { params: { type: "dub" } })
      .then((r) => {
        if (cancelled) return;
        setMyPacks(r.data.mine);
        setCommunityPacks(r.data.community);
      })
      .catch(() => {
        if (!cancelled) {
          setMyPacks([]);
          setCommunityPacks([]);
        }
      });
    return () => {
      cancelled = true;
    };
  }, []);

  async function handleCreate(e: FormEvent) {
    e.preventDefault();
    setError(null);
    setCreating(true);
    try {
      const response = await api.post<CreateDubRoomResponse>("/api/dub-rooms", {
        player_mode: playerMode,
        line_timer_seconds: solo ? 0 : lineTimerSeconds,
        ...(packId !== null ? { dataset_id: packId } : {}),
      });
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
        Claim a character in a short clip, record your lines in order, then watch the dub —
        with friends, or solo voicing every part yourself.
      </p>
      <Card className="dub-landing-card">
        <form onSubmit={handleCreate}>
          <fieldset className="dub-mode-picker">
            <legend>How are you playing?</legend>
            <label className={playerMode === "multiplayer" ? "dub-mode-option is-on" : "dub-mode-option"}>
              <input
                type="radio"
                name="player_mode"
                checked={playerMode === "multiplayer"}
                onChange={() => setPlayerMode("multiplayer")}
              />
              <span>
                <strong>With friends</strong>
                <span className="hint">Everyone claims a character and records their lines, then you rate the dub together.</span>
              </span>
            </label>
            <label className={solo ? "dub-mode-option is-on" : "dub-mode-option"}>
              <input type="radio" name="player_mode" checked={solo} onChange={() => setPlayerMode("solo")} />
              <span>
                <strong>Solo</strong>
                <span className="hint">Just you — voice every character, at your own pace. No score.</span>
              </span>
            </label>
          </fieldset>

          <label>
            Clip source
            <select value={packId ?? ""} onChange={(e) => setPackId(e.target.value === "" ? null : Number(e.target.value))}>
              <option value="">Single clip (pick one in the lobby)</option>
              {myPacks.length > 0 && (
                <optgroup label="My packs">
                  {myPacks.map((p) => (
                    <option key={p.id} value={p.id} disabled={p.item_count === 0}>
                      {p.name}
                      {p.item_count === 0 ? " (empty)" : ` (${p.item_count} clips)`}
                    </option>
                  ))}
                </optgroup>
              )}
              {communityPacks.length > 0 && (
                <optgroup label="Community">
                  {communityPacks.map((p) => (
                    <option key={p.id} value={p.id} disabled={p.item_count === 0}>
                      {p.name} — {p.owner_username ?? "someone"}
                      {p.item_count === 0 ? " (empty)" : ` (${p.item_count} clips)`}
                    </option>
                  ))}
                </optgroup>
              )}
            </select>
          </label>
          {packId !== null && (
            <p className="hint">Playing a pack — its clips run as rounds; you won't pick a clip in the lobby.</p>
          )}

          {!solo && (
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
          )}

          {error && <p className="form-error">{error}</p>}
          <Button type="submit" variant="grape" size="lg" disabled={creating}>
            {creating ? "Creating…" : solo ? "Start solo" : "Create game"}
          </Button>
        </form>
      </Card>
    </div>
  );
}
