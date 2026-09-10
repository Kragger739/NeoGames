import { useEffect, useState } from "react";
import { useNavigate, useParams } from "react-router-dom";
import { Copy, LogOut, Mic } from "lucide-react";

import { api } from "../lib/api";
import { firstValidationError } from "../lib/errors";
import { dubPathForState } from "../lib/dubNav";
import { leaveRoomOnServer } from "../lib/leaveRoom";
import { getPlayerId, getPlayerToken } from "../lib/playerToken";
import { useDubStore } from "../stores/dubStore";
import { Button } from "../components/ui/Button";
import { IconButton } from "../components/ui/IconButton";
import { DubClipPicker } from "../components/dub/DubClipPicker";

export function DubLobbyPage() {
  const { code } = useParams<{ code: string }>();
  const navigate = useNavigate();

  const connect = useDubStore((s) => s.connect);
  const resync = useDubStore((s) => s.resync);
  const leaveRoom = useDubStore((s) => s.leaveRoom);
  const state = useDubStore((s) => s.state);
  const hostName = useDubStore((s) => s.hostName);
  const players = useDubStore((s) => s.players);
  const clip = useDubStore((s) => s.clip);
  const roleAssignments = useDubStore((s) => s.roleAssignments);
  const caughtUp = useDubStore((s) => s.caughtUp);

  const myPlayerId = getPlayerId();

  const [linkCopied, setLinkCopied] = useState(false);
  const [busy, setBusy] = useState(false);
  const [error, setError] = useState<string | null>(null);

  // No seat in this browser -> send them through the join flow first.
  useEffect(() => {
    if (code && !getPlayerToken()) navigate(`/play/${code}`, { replace: true });
  }, [code, navigate]);

  useEffect(() => {
    if (code) connect(code);
  }, [code, connect]);

  useEffect(() => {
    if (caughtUp && code && state !== "lobby" && state !== "role_claim") {
      navigate(dubPathForState(code, state));
    }
  }, [caughtUp, state, code, navigate]);

  const me = players.find((p) => p.room_player_id === myPlayerId);
  // The host's own account is seated as a player; the server marks that seat.
  // Host controls are only a UI convenience - every host route re-checks host_id.
  const iAmHostSeat = me?.is_host ?? false;

  async function toggleMic() {
    if (!code || !me) return;
    try {
      await api.patch(`/api/dub-rooms/${code}/mic-ready`, { mic_ready: !me.mic_ready });
    } catch {
      /* roster refreshes via broadcast */
    }
  }

  async function pickClip(clipId: number) {
    if (!code) return;
    setError(null);
    try {
      await api.post(`/api/dub-rooms/${code}/clip`, { clip_id: clipId });
    } catch (err) {
      setError(firstValidationError(err));
    }
  }

  async function claim(characterId: number) {
    if (!code) return;
    try {
      await api.post(`/api/dub-rooms/${code}/claims`, { character_id: characterId });
    } catch (err) {
      setError(firstValidationError(err));
    }
  }

  async function unclaim(characterId: number) {
    if (!code) return;
    try {
      await api.delete(`/api/dub-rooms/${code}/claims/${characterId}`);
    } catch (err) {
      setError(firstValidationError(err));
    }
  }

  async function startGame() {
    if (!code) return;
    setError(null);
    setBusy(true);
    try {
      await api.post(`/api/dub-rooms/${code}/start`);
      await resync(code);
    } catch (err) {
      setError(firstValidationError(err));
      void resync(code).catch(() => {});
    } finally {
      setBusy(false);
    }
  }

  async function beginRecording() {
    if (!code) return;
    setError(null);
    setBusy(true);
    try {
      await api.post(`/api/dub-rooms/${code}/begin-recording`);
      await resync(code);
    } catch (err) {
      setError(firstValidationError(err));
    } finally {
      setBusy(false);
    }
  }

  async function handleLeave() {
    if (code) await leaveRoomOnServer(code);
    leaveRoom();
    navigate("/");
  }

  async function copyInvite() {
    if (!code) return;
    try {
      await navigator.clipboard.writeText(`${window.location.origin}/play/${code}`);
      setLinkCopied(true);
      setTimeout(() => setLinkCopied(false), 2000);
    } catch {
      /* clipboard blocked - nothing to fall back to */
    }
  }

  const micReadyCount = players.filter((p) => p.mic_ready).length;
  const canStart = clip?.status === "ready" && players.length >= 2 && micReadyCount === players.length;
  const claimByCharacter = new Map(roleAssignments.map((a) => [a.character_id, a.room_player_id]));
  const everyCharacterClaimed =
    (clip?.characters.length ?? 0) > 0 && clip!.characters.every((c) => claimByCharacter.get(c.id) != null);

  return (
    <div className="dub-lobby-page">
      <h1>DUB TOGETHER</h1>
      <div className="dub-lobby-invite">
        <p className="room-ticket">{code?.toUpperCase()}</p>
        <Button variant="ghost" onClick={() => void copyInvite()}>
          <Copy size={16} strokeWidth={2.5} />
          {linkCopied ? "Copied!" : "Copy invite link"}
        </Button>
        <IconButton icon={LogOut} label="Leave room" onClick={() => void handleLeave()} />
      </div>

      <section className="dub-lobby-section">
        <h2>Your mic</h2>
        <Button variant={me?.mic_ready ? "turquoise" : "ghost"} onClick={() => void toggleMic()}>
          <Mic size={16} strokeWidth={2.5} />
          {me?.mic_ready ? "Mic ready ✓" : "I'm ready"}
        </Button>
      </section>

      <section className="dub-lobby-section">
        <h2>Players</h2>
        <ul className="dub-roster">
          {players.map((p) => (
            <li key={p.room_player_id} className="dub-roster-row">
              <span>{p.room_player_id === myPlayerId ? `${p.nickname} (you)` : p.nickname}</span>
              <span className={p.mic_ready ? "dub-ready-badge is-yes" : "dub-ready-badge"}>
                {p.mic_ready ? "Ready" : "Not ready"}
              </span>
            </li>
          ))}
        </ul>
      </section>

      {state === "role_claim" && clip ? (
        <section className="dub-lobby-section">
          <h2>Claim a character</h2>
          <div className="dub-character-grid">
            {clip.characters.map((c) => {
              const holder = claimByCharacter.get(c.id) ?? null;
              const mine = holder === myPlayerId;
              const holderName = players.find((p) => p.room_player_id === holder)?.nickname;
              return (
                <div
                  key={c.id}
                  className={`dub-character-chip${mine ? " is-mine" : ""}${holder && !mine ? " is-taken" : ""}`}
                  data-hue={c.color ?? "grape"}
                >
                  <span className="dub-character-name">{c.display_name}</span>
                  {holder == null ? (
                    <Button variant="grape" onClick={() => void claim(c.id)}>
                      Claim
                    </Button>
                  ) : mine ? (
                    <Button variant="ghost" onClick={() => void unclaim(c.id)}>
                      Release
                    </Button>
                  ) : (
                    <span className="hint">{holderName ?? "Taken"}</span>
                  )}
                </div>
              );
            })}
          </div>
          {iAmHostSeat && (
            <Button variant="grape" size="lg" disabled={busy || !everyCharacterClaimed} onClick={() => void beginRecording()}>
              {busy ? "Starting…" : everyCharacterClaimed ? "Start recording" : "Waiting for every character…"}
            </Button>
          )}
          {!iAmHostSeat && <p className="hint">Waiting for {hostName ?? "the host"} to start recording…</p>}
        </section>
      ) : (
        <section className="dub-lobby-section">
          <h2>{iAmHostSeat ? "Pick a clip" : "Clip"}</h2>
          {iAmHostSeat ? (
            <DubClipPicker selectedClipId={clip?.id ?? null} onPick={pickClip} />
          ) : (
            <p className="hint">{clip ? clip.title : "Waiting for the host to pick a clip…"}</p>
          )}
        </section>
      )}

      {error && <p className="form-error">{error}</p>}

      {iAmHostSeat && state === "lobby" && (
        <>
          <Button variant="grape" size="lg" disabled={!canStart || busy} onClick={() => void startGame()}>
            {busy ? "Starting…" : "Start game"}
          </Button>
          {!canStart && (
            <p className="hint">Needs a ready clip and at least 2 players, everyone mic-ready.</p>
          )}
        </>
      )}
    </div>
  );
}
