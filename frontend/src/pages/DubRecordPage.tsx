import { useEffect, useRef, useState } from "react";
import { useNavigate, useParams } from "react-router-dom";

import { api } from "../lib/api";
import { firstValidationError } from "../lib/errors";
import { dubPathForState } from "../lib/dubNav";
import { getPlayerId } from "../lib/playerToken";
import { useDubStore } from "../stores/dubStore";
import { Button } from "../components/ui/Button";

export function DubRecordPage() {
  const { code } = useParams<{ code: string }>();
  const navigate = useNavigate();

  const connect = useDubStore((s) => s.connect);
  const state = useDubStore((s) => s.state);
  const clip = useDubStore((s) => s.clip);
  const currentLine = useDubStore((s) => s.currentLine);
  const currentLineIndex = useDubStore((s) => s.currentLineIndex);
  const totalLines = useDubStore((s) => s.totalLines);
  const assignedPlayerId = useDubStore((s) => s.assignedPlayerId);
  const roleAssignments = useDubStore((s) => s.roleAssignments);
  const takes = useDubStore((s) => s.takes);
  const players = useDubStore((s) => s.players);
  const caughtUp = useDubStore((s) => s.caughtUp);

  const videoRef = useRef<HTMLVideoElement>(null);
  const [submitting, setSubmitting] = useState(false);
  const [error, setError] = useState<string | null>(null);

  const myPlayerId = getPlayerId();
  const isMine = assignedPlayerId != null && assignedPlayerId === myPlayerId;
  const me = players.find((p) => p.room_player_id === myPlayerId);

  useEffect(() => {
    if (code) connect(code);
  }, [code, connect]);

  useEffect(() => {
    if (caughtUp && code && state !== "recording" && state !== "assembling") {
      navigate(dubPathForState(code, state));
    }
  }, [caughtUp, state, code, navigate]);

  // Loop the reference video over just the current line's window.
  useEffect(() => {
    const video = videoRef.current;
    if (!video || !currentLine) return;
    const start = currentLine.start_ms / 1000;
    const end = currentLine.end_ms / 1000;
    video.currentTime = start;
    const onTime = () => {
      if (video.currentTime >= end) video.currentTime = start;
    };
    video.addEventListener("timeupdate", onTime);
    void video.play().catch(() => {});
    return () => video.removeEventListener("timeupdate", onTime);
  }, [currentLine]);

  async function submitTake() {
    if (!code || !currentLine) return;
    setError(null);
    setSubmitting(true);
    try {
      // PHASE 1 stub: no real audio yet (Phase 4 wires MediaRecorder). The
      // backend accepts a placeholder path so the whole loop is walkable.
      await api.post(`/api/dub-rooms/${code}/lines/${currentLine.id}/take`, {
        audio_path: `dub/stub/${code}-line-${currentLine.id}-${Date.now()}.webm`,
        duration_ms: currentLine.end_ms - currentLine.start_ms,
      });
    } catch (err) {
      setError(firstValidationError(err));
    } finally {
      setSubmitting(false);
    }
  }

  const characterName = (characterId: number | undefined) =>
    clip?.characters.find((c) => c.id === characterId)?.display_name ?? "—";
  const assignedNickname = players.find((p) => p.room_player_id === assignedPlayerId)?.nickname ?? "Someone";
  const claimedCharacterIds = new Set(
    roleAssignments.filter((a) => a.room_player_id != null).map((a) => a.character_id),
  );
  const takenLineIds = new Set(takes.map((t) => t.line_id));

  if (state === "assembling") {
    return (
      <div className="dub-record-page">
        <h1>Stitching your dub…</h1>
        <div className="dub-spinner" aria-label="Assembling" />
        <p className="hint">Layering everyone's takes over the clip. Hang tight.</p>
      </div>
    );
  }

  return (
    <div className="dub-record-page">
      <h1>Recording — line {currentLineIndex + 1} of {totalLines}</h1>

      <div className="dub-record-layout">
        <div className="dub-record-stage">
          {clip?.video_url ? (
            <video ref={videoRef} className="dub-record-video" src={clip.video_url} muted playsInline />
          ) : (
            <div className="dub-record-video dub-record-video-empty">No video</div>
          )}
        </div>

        <ol className="dub-script">
          {clip?.lines.map((line) => {
            const cls = [
              "dub-script-line",
              line.position === currentLineIndex ? "is-current" : "",
              line.position < currentLineIndex || takenLineIds.has(line.id) ? "is-done" : "",
              !claimedCharacterIds.has(line.character_id) ? "is-skipped" : "",
              line.character_id === me?.claimed_character_id ? "is-mine" : "",
            ]
              .filter(Boolean)
              .join(" ");
            return (
              <li key={line.id} className={cls}>
                <span className="dub-script-character">{characterName(line.character_id)}</span>
                <span className="dub-script-text">{line.text || <em>(no transcript)</em>}</span>
              </li>
            );
          })}
        </ol>
      </div>

      {error && <p className="form-error">{error}</p>}

      {isMine ? (
        <div className="dub-recorder">
          <p className="hint">Your line as {characterName(currentLine?.character_id)}.</p>
          <Button variant="grape" size="lg" disabled={submitting} onClick={() => void submitTake()}>
            {submitting ? "Saving…" : takenLineIds.has(currentLine?.id ?? -1) ? "Re-record this line" : "Record this line"}
          </Button>
        </div>
      ) : (
        <p className="hint">🎬 {assignedNickname} is recording line {currentLineIndex + 1}…</p>
      )}
    </div>
  );
}
